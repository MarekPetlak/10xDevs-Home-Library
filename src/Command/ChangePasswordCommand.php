<?php

declare(strict_types=1);

namespace App\Command;

use App\Exception\UserManagementException;
use App\Service\UserManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:user:change-password', description: 'Change the password of a household account (password is asked interactively)')]
final class ChangePasswordCommand extends Command
{
    public function __construct(private readonly UserManager $userManager)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('email', InputArgument::REQUIRED, 'E-mail address of the account');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $email = (string) $input->getArgument('email');

        $password = $this->askPassword($io);
        if (null === $password) {
            return Command::FAILURE;
        }

        try {
            $user = $this->userManager->changePassword($email, $password);
        } catch (UserManagementException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->success(sprintf('Password for "%s" changed.', $user->getEmail()));

        return Command::SUCCESS;
    }

    private function askPassword(SymfonyStyle $io): ?string
    {
        $question = (new Question('New password'))->setHidden(true)->setHiddenFallback(false);
        $confirmation = (new Question('Repeat new password'))->setHidden(true)->setHiddenFallback(false);

        $password = (string) $io->askQuestion($question);
        $repeated = (string) $io->askQuestion($confirmation);

        if ($password !== $repeated) {
            $io->error('The passwords do not match.');

            return null;
        }

        return $password;
    }
}
