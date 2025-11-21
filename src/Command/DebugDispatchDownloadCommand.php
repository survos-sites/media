<?php
declare(strict_types=1);

namespace App\Command;

use Survos\StateBundle\Message\TransitionMessage;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\SentStamp;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;
use Jwage\PhpAmqpLibMessengerBundle\Transport\AmqpStamp as JwageAmqpStamp;
use Symfony\Component\Messenger\Bridge\Amqp\Transport\AmqpStamp as SymfonyAmqpStamp;

#[AsCommand('app:debug:dispatch-download', 'Dispatch a TransitionMessage to the download queue (or sync if missing).')]
final class DebugDispatchDownloadCommand
{
    public function __construct(
        private MessageBusInterface $bus,
    ) {}

    public function __invoke(
        SymfonyStyle $io,

        #[Argument('Entity id to act on (e.g., ULID or integer)')]
        string $id,

        #[Option('Fully-qualified class name (default: App\\Entity\\Media)')]
        string $class = 'App\\Entity\\Media',

        #[Option('Transition name (default: download)')]
        string $transition = 'download',

        #[Option('Workflow name (default: MediaWorkflow)')]
        string $workflow = 'MediaWorkflow',

        #[Option('Sender/transport alias to target (default: download; pass empty to run sync)')]
        string $transport = 'download',

        #[Option('Force-add JWage AmqpStamp routing key (debug)')]
        bool $forceAmqp = false,
    ): int {
        $io->title('Dispatch TransitionMessage → download');
        $io->listing([
            "Class: $class",
            "ID: $id",
            "Transition: $transition",
            "Workflow: $workflow",
            "Transport (sender): " . ($transport === '' ? '(sync)' : $transport),
            "Force AmqpStamp: " . ($forceAmqp ? 'yes' : 'no'),
        ]);

        $stamps = [];
        if ($transport !== '') {
            // IMPORTANT: array form in Symfony 7.3
            $stamps[] = new TransportNamesStamp([$transport]);
        }
        if ($forceAmqp) {
            // Debug: add routing key explicitly; normally your middleware does this.
            $stamps[] = new JwageAmqpStamp($transition);
        }

        $message  = new TransitionMessage($id, $class, $transition, $workflow);
        $envelope = $this->bus->dispatch($message, $stamps);

        $io->section('Envelope Debug');
        $tn = $envelope->last(TransportNamesStamp::class);
        $io->writeln('TransportNamesStamp: ' . ($tn ? json_encode($tn->getTransportNames()) : '<none>'));

        $jwage = $envelope->last(JwageAmqpStamp::class);
        $symfy = $envelope->last(SymfonyAmqpStamp::class);
        $io->writeln('JWage AmqpStamp: ' . ($jwage ? '<present>' : '<none>'));
        $io->writeln('Symfony AmqpStamp: ' . ($symfy ? '<present>' : '<none>'));

        $sent = $envelope->all(SentStamp::class);
        if ($sent) {
            foreach ($sent as $s) {
                $io->writeln(sprintf(
                    'Sent via: %s (alias: %s)',
                    $s->getSenderClass(),
                    (string) $s->getSenderAlias()
                ));
            }
        } else {
            $io->writeln('<comment>No SentStamp found (likely handled sync).</comment>');
        }

        $io->success('Dispatched.');
        $io->newLine();
        $io->writeln('Next:');
        $io->writeln('  • Check counts:  bin/console messenger:stats');
        $io->writeln('  • Consume:       bin/console messenger:consume -vv download');

        return 0;
    }
}
