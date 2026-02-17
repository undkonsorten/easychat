<?php
declare(strict_types=1);
namespace Undkonsorten\Easychat\Command;

use TYPO3\CMS\Extbase\Persistence\Exception\IllegalObjectTypeException;
use TYPO3\CMS\Extbase\Persistence\Exception\InvalidQueryException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\CMS\Extbase\Persistence\PersistenceManagerInterface;
use Undkonsorten\Easychat\Domain\Model\Session;
use Undkonsorten\Easychat\Domain\Repository\SessionRepository;

class DeleteSessionsCommand extends Command
{

   public function __construct(
       private readonly SessionRepository           $sessionRepository,
       private readonly PersistenceManagerInterface $persistenceManager,
       ?string                                      $name = null,
       ?callable                                    $code = null
   )
   {
       parent::__construct($name,$code);
   }

    protected function configure()
    {
        $this->addArgument('keepDateInterval',InputArgument::OPTIONAL, 'Date interval of sessions to be kept.','P3M');
        parent::configure();
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     * @throws IllegalObjectTypeException
     * @throws InvalidQueryException
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $sessions = $this->sessionRepository->findOutOfInterval(new \DateInterval($input->getArgument('keepDateInterval')));
        if ($sessions->count() === 0) {
            $output->writeln("<info>No sessions found older than " . $input->getArgument('keepDateInterval') . "</info>");
        } else {
            foreach ($sessions as $session) {
                /**@var Session $session */
                $this->sessionRepository->remove($session);
                $output->writeln("<info>Task " . $session->getSessionId() . " has been deleted.</info>", OutputInterface::VERBOSITY_VERY_VERBOSE);
            }
            $output->writeln(
                sprintf(
                    "<info>%d sessions found older than %s were deleted</info>",
                    $sessions->count(),
                    $input->getArgument('keepDateInterval')
                ),
                OutputInterface::VERBOSITY_VERBOSE
            );
        }
        $this->persistenceManager->persistAll();
        return 0;
    }

}
