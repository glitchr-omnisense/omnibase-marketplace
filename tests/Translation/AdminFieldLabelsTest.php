<?php

namespace Tests\Base\Marketplace\Translation;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * The back office's columns and fields: left without a label, a field is named
 * after its property in English ("Title", "Unit price"), whatever the language
 * of the screen. Each field of the CRUD controllers names its label, a text of
 * "marketplace.admin.field.*", and every language the bundle carries has it.
 */
class AdminFieldLabelsTest extends TestCase
{
    private const LANGUAGES = ['fr', 'en', 'ja'];

    /** Names that are the same in every language. */
    private const SAME_EVERYWHERE = ['SIRET', 'Incoterm'];

    /** @return list<array{string, string, ?string}> controller, property, label */
    private function fields(): array
    {
        $fields = [];
        foreach (glob(\dirname(__DIR__, 2).'/src/Controller/Admin/Crud/*.php') as $file) {
            preg_match_all('/(?:\w+Field)::new\(\s*\'([\w.]+)\'(?:\s*,\s*\'([^\']*)\')?\s*\)/', file_get_contents($file), $found, \PREG_SET_ORDER);
            foreach ($found as $field) {
                $fields[] = [basename($file, '.php'), $field[1], $field[2] ?? null];
            }
        }

        return $fields;
    }

    /** @return array<string, string> */
    private function labels(string $language): array
    {
        $yaml = Yaml::parseFile(\dirname(__DIR__, 2).'/translations/marketplace+intl-icu.'.$language.'.yaml');

        return $yaml['admin']['field'] ?? [];
    }

    public function testEveryFieldNamesItsLabel(): void
    {
        $fields = $this->fields();
        self::assertGreaterThan(100, \count($fields), 'the controllers were read');
        foreach ($fields as [$controller, $property, $label]) {
            if ('id' === $property) {
                continue;
            }
            self::assertNotNull($label, "$controller: \"$property\" has no label, so the screen says it in English");
            if (\in_array($label, self::SAME_EVERYWHERE, true)) {
                continue;
            }
            self::assertStringStartsWith('@', $label, "$controller: the label of \"$property\" is a text in one language");
        }
    }

    public function testEveryLabelIsTranslatedInEveryLanguage(): void
    {
        $used = [];
        foreach ($this->fields() as [$controller, $property, $label]) {
            if (null !== $label && 1 === preg_match('/^@marketplace\.admin\.field\.(\w+)$/', $label, $m)) {
                $used[$m[1]][] = "$controller::$property";
            }
        }
        self::assertNotEmpty($used);
        foreach (self::LANGUAGES as $language) {
            $labels = $this->labels($language);
            foreach ($used as $key => $where) {
                self::assertArrayHasKey($key, $labels, "$language: admin.field.$key (".$where[0].')');
                self::assertNotSame('', trim((string) $labels[$key]));
            }
            self::assertSame([], array_diff(array_keys($labels), array_keys($used)), "$language: texts no field uses");
        }
    }
}
