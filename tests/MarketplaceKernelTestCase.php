<?php

namespace Tests\Base\Marketplace;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * For the tests that write to a database (rights, credits, lists,
 * referrals): they need a host application - its kernel, its User, its
 * schema - and are skipped in a bare checkout. In omnisense/omnibase's Docker
 * harness the database is a fresh SQLite file at each run.
 */
abstract class MarketplaceKernelTestCase extends KernelTestCase
{
    protected EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $_SERVER['KERNEL_CLASS'] ??= $_ENV['KERNEL_CLASS'] ?? 'App\\Kernel';
        if (!class_exists($_SERVER['KERNEL_CLASS']) || !class_exists('App\\Entity\\User')) {
            self::markTestSkipped('Needs a host application (its kernel, its User, a database).');
        }
        self::bootKernel();
        $this->entityManager = self::getContainer()->get('doctrine')->getManager();
    }

    /**
     * PHPUnit keeps every test case to the end of the run: what a case holds
     * - its entity manager, the services and entities it took from the
     * container - would keep each kernel it booted alive with it (10 to 15 MB
     * a test, a gigabyte by the end). Once the kernel is shut down, the
     * properties of the case and of this class go.
     */
    protected function tearDown(): void
    {
        parent::tearDown();

        for ($class = new \ReflectionClass($this); $class->getName() !== KernelTestCase::class; $class = $class->getParentClass()) {
            foreach ($class->getProperties() as $property) {
                if ($property->getDeclaringClass()->getName() !== $class->getName() || $property->isStatic() || $property->isReadOnly() || !$property->isInitialized($this)) {
                    continue;
                }
                $name = $property->getName();
                \Closure::bind(function () use ($name): void { unset($this->{$name}); }, $this, $class->getName())();
            }
        }
    }

    /** A member, stored: the application's User. */
    protected function user(string $name = 'member'): \Base\Entity\User
    {
        $suffix = bin2hex(random_bytes(4));
        $user = new \App\Entity\User();
        $user->setEmail(sprintf('%s-%s@example.org', $name, $suffix));
        if (method_exists($user, 'setUsername')) {
            $user->setUsername($name.'-'.$suffix);
        }
        $user->setPlainPassword(bin2hex(random_bytes(8)));
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }
}
