<?php

declare(strict_types=1);

namespace App\Command;

use App\Exception\UserManagementException;
use App\Service\PasswordPrompt;
use App\Service\UserManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:user:create', description: 'Create a household account (password is asked interactively)')]
final class CreateUserCommand extends Command
{
    public function __construct(
        private readonly UserManager $userManager,
        private readonly PasswordPrompt $passwordPrompt,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('email', InputArgument::REQUIRED, 'E-mail address used to log in');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $email = (string) $input->getArgument('email');

        $password = $this->passwordPrompt->ask($io, 'Password', 'Repeat password');
        if (null === $password) {
            return Command::FAILURE;
        }

        try {
            $user = $this->userManager->create($email, $password);
        } catch (UserManagementException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->success(sprintf('User "%s" created.', $user->getEmail()));

        return Command::SUCCESS;
    }

}
