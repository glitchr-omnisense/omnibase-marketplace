<?php

namespace Tests\Base\Marketplace\Http;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * A form of the back office as a browser handles it when no script ran - one
 * that blocks them, a page whose script failed: read from the page's HTML,
 * sent back with the fields as they are printed, nothing a script would have
 * filled.
 */
trait BackOfficeFormTrait
{
    /** @var array<string, string> */
    private array $cookies = [];

    /** Signed in as a creator of the marketplace, by a session as after a real sign-in. */
    private function signInToTheBackOffice(): void
    {
        if (!class_exists('Base\\Admin\\Controller\\AbstractCrudController')) {
            self::markTestSkipped('Needs omnibase/admin.');
        }
        $_SERVER['REMOTE_ADDR'] ??= '127.0.0.1';
        $_SERVER['HTTP_USER_AGENT'] ??= 'phpunit';

        $admin = $this->user('creator');
        $admin->setRoles(['ROLE_SUPERADMIN']);
        $this->entityManager->flush();
        $session = self::getContainer()->get('session.factory')->createSession();
        $session->set('_security_main', serialize(new UsernamePasswordToken($admin, 'main', $admin->getRoles())));
        $session->save();
        $this->cookies = [$session->getName() => $session->getId()];
    }

    /** @param array<string, mixed>|null $post */
    private function ask(string $path, ?array $post = null): Response
    {
        $request = Request::create($path, null === $post ? 'GET' : 'POST', $post ?? [], $this->cookies, [], ['HTTP_ORIGIN' => 'http://localhost']);
        $response = self::$kernel->handle($request);
        foreach ($response->headers->getCookies() as $cookie) {
            $this->cookies[$cookie->getName()] = (string) $cookie->getValue();
        }

        return $response;
    }

    /** A page opened as a browser does, to where it leads (a record's address may be its slug's). */
    private function open(string $path): Response
    {
        $response = $this->ask($path);
        for ($n = 0; $n < 3 && $response->isRedirection(); ++$n) {
            $response = $this->ask((string) parse_url((string) $response->headers->get('Location'), \PHP_URL_PATH));
        }

        return $response;
    }

    /**
     * What the form given back says is wrong, by field ('' for the form itself).
     *
     * @return array<string, list<string>>
     */
    private function errors(Response $response): array
    {
        $errors = [];
        foreach (explode('<div class="form-row"', (string) $response->getContent()) as $n => $row) {
            if (!preg_match_all('~<li><span>(.*?)</span></li>~s', $row, $messages)) {
                continue;
            }
            $field = 0 === $n ? '' : (preg_match('~name="crud_form\[([^\]"]+)\]~', $row, $name) ? $name[1] : '?');
            $errors[$field] = array_map(static fn (string $message) => html_entity_decode(trim(strip_tags($message))), $messages[1]);
        }

        return $errors;
    }

    private function said(Response $response): string
    {
        $status = $response->getStatusCode();
        if ($status >= 500 || 404 === $status) {
            return $status.' '.substr(trim(preg_replace('/\s+/', ' ', strip_tags((string) preg_replace('~<(style|script)\b.*?</\1>~s', '', (string) $response->getContent())))), 0, 600);
        }

        return $status.' '.$response->headers->get('Location').' '.json_encode($this->errors($response), \JSON_UNESCAPED_UNICODE);
    }

    /**
     * A form of the back office as printed: its fields' names and the values a browser would send back untouched.
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function form(string $path): array
    {
        $response = $this->ask($path);
        self::assertSame(200, $response->getStatusCode(), $this->said($response));
        $html = (string) $response->getContent();

        // As a browser gathers them: an input's value, a ticked box, a textarea's text, a <select>'s
        // selected options - the first one of a single <select> when none is marked.
        $pairs = [];
        preg_match_all('~<input[^>]*\sname="(crud_form\[[^"]+)"[^>]*>~', $html, $inputs, \PREG_SET_ORDER);
        foreach ($inputs as [$tag, $name]) {
            if (preg_match('/type="(file|submit|button)"/', $tag) || (preg_match('/type="(checkbox|radio)"/', $tag) && !preg_match('/\schecked/', $tag))) {
                continue;
            }
            $pairs[] = [$name, preg_match('/\svalue="([^"]*)"/', $tag, $value) ? $value[1] : ''];
        }
        preg_match_all('~<textarea[^>]*\sname="(crud_form\[[^"]+)"[^>]*>(.*?)</textarea>~s', $html, $areas, \PREG_SET_ORDER);
        foreach ($areas as [, $name, $text]) {
            $pairs[] = [$name, $text];
        }
        preg_match_all('~<select([^>]*)\sname="(crud_form\[[^"]+)"([^>]*)>(.*?)</select>~s', $html, $selects, \PREG_SET_ORDER);
        foreach ($selects as [, $before, $name, $after, $options]) {
            preg_match_all('~<option([^>]*)>~', $options, $tags);
            $values = array_map(static fn (string $tag) => [preg_match('/\svalue="([^"]*)"/', $tag, $value) ? $value[1] : '', (bool) preg_match('/\sselected/', $tag)], $tags[1]);
            $chosen = array_column(array_filter($values, static fn (array $option) => $option[1]), 0);
            if (!$chosen && $values && !preg_match('/\smultiple/', $before.$after)) {
                $chosen = [$values[0][0]];
            }
            foreach ($chosen as $value) {
                $pairs[] = [$name, $value];
            }
        }
        parse_str(implode('&', array_map(static fn (array $pair) => urlencode($pair[0]).'='.urlencode(html_entity_decode($pair[1])), $pairs)), $sent);

        return [$html, $sent['crud_form'] ?? []];
    }

    /** The <select> of a field, as printed. */
    private function select(string $html, string $field): string
    {
        return preg_match('~<select[^>]*name="crud_form\['.preg_quote($field, '~').'\](?:\[choice\])?(?:\[\])?"[^>]*>.*?</select>~s', $html, $select) ? $select[0] : '';
    }

    /**
     * What the browser sends back for that form, the fields given here typed by hand.
     *
     * @param array<string, mixed> $typed
     */
    private function send(array $typed, string $path): Response
    {
        [, $fields] = $this->form($path);

        return $this->ask($path, ['crud_form' => array_replace($fields, $typed)]);
    }
}
