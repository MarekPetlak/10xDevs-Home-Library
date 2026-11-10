<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\PasswordPrompt;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

final class PasswordPromptTest extends TestCase
{
    public function testReturnsThePasswordWhenBothEntriesMatch(): void
    {
        [$io] = $this->io("secret-pass\nsecret-pass\n");

        self::assertSame('secret-pass', (new PasswordPrompt())->ask($io, 'Password', 'Repeat password'));
    }

    public function testReturnsNullAndReportsAMismatch(): void
    {
        [$io, $output] = $this->io("secret-pass\ndifferent-pass\n");

        self::assertNull((new PasswordPrompt())->ask($io, 'Password', 'Repeat password'));
        self::assertStringContainsString('The passwords do not match.', $output->fetch());
    }

    public function testUsesTheGivenLabels(): void
    {
        [$io, $output] = $this->io("secret-pass\nsecret-pass\n");

        (new PasswordPrompt())->ask($io, 'New password', 'Repeat new password');

        $display = $output->fetch();
        self::assertStringContainsString('New password', $display);
        self::assertStringContainsString('Repeat new password', $display);
    }

    /**
     * @return array{0: SymfonyStyle, 1: BufferedOutput}
     */
    private function io(string $typed): array
    {
        $stream = fopen('php://memory', 'r+');
        self::assertIsResource($stream);
        fwrite($stream, $typed);
        rewind($stream);

        $input = new ArrayInput([]);
        $input->setStream($stream);
        $input->setInteractive(true);
        $output = new BufferedOutput();

        return [new SymfonyStyle($input, $output), $output];
    }
}
