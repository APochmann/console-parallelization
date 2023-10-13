<?php

/*
 * This file is part of the Webmozarts Console Parallelization package.
 *
 * (c) Webmozarts GmbH <office@webmozarts.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Webmozarts\Console\Parallelization;

use Closure;
use Symfony\Component\Console\Input\Input;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;
use Webmozart\Assert\Assert;
use Webmozarts\Console\Parallelization\ErrorHandler\ErrorHandler;
use Webmozarts\Console\Parallelization\Input\ChildCommandFactory;
use Webmozarts\Console\Parallelization\Input\ParallelizationInput;
use Webmozarts\Console\Parallelization\Logger\Logger;
use Webmozarts\Console\Parallelization\Process\ProcessLauncher;
use Webmozarts\Console\Parallelization\Process\ProcessLauncherFactory;
use function mb_strlen;
use function min;
use function sprintf;

final class ParallelExecutor
{
    /**
     * @internal The ParallelExecutor should only be created via its factory
     *           ParallelExecutorFactory. This method signature is not subject
     *           to the BC policy.
     *
     * @param Closure(InputInterface):iterable<string>                    $fetchItems
     * @param Closure(string, InputInterface, OutputInterface):void       $runSingleCommand
     * @param Closure(positive-int|0|null):string                         $getItemName
     * @param resource                                                    $childSourceStream
     * @param positive-int                                                $batchSize
     * @param positive-int                                                $segmentSize
     * @param Closure(InputInterface, OutputInterface):void               $runBeforeFirstCommand
     * @param Closure(InputInterface, OutputInterface):void               $runAfterLastCommand
     * @param Closure(InputInterface, OutputInterface, list<string>):void $runBeforeBatch
     * @param Closure(InputInterface, OutputInterface, list<string>):void $runAfterBatch
     * @param array<string, string>                                       $extraEnvironmentVariables
     * @param Closure(): void                                             $processTick
     */
    public function __construct(
        private readonly Closure $fetchItems,
        private readonly Closure $runSingleCommand,
        private readonly Closure $getItemName,
        private readonly ErrorHandler $errorHandler,
        private $childSourceStream,
        private readonly int $batchSize,
        private readonly int $segmentSize,
        private readonly Closure $runBeforeFirstCommand,
        private readonly Closure $runAfterLastCommand,
        private readonly Closure $runBeforeBatch,
        private readonly Closure $runAfterBatch,
        private readonly string $progressSymbol,
        private readonly ChildCommandFactory $childCommandFactory,
        private readonly string $workingDirectory,
        private readonly ?array $extraEnvironmentVariables,
        private readonly ProcessLauncherFactory $processLauncherFactory,
        private readonly Closure $processTick
    ) {
        self::validateSegmentSize($segmentSize);
        self::validateBatchSize($batchSize);
        self::validateProgressSymbol($progressSymbol);
    }

    /**
     * @return 0|positive-int
     */
    public function execute(
        ParallelizationInput $parallelizationInput,
        InputInterface $input,
        OutputInterface $output,
        Logger $logger
    ): int {
        if ($parallelizationInput->isChildProcess()) {
            return $this->executeChildProcess(
                $parallelizationInput,
                $input,
                $output,
                $logger,
            );
        }

        return $this->executeMainProcess(
            $parallelizationInput,
            $input,
            $output,
            $logger,
        );
    }

    /**
     * Executes the main process.
     *
     * The main process spawns as many child processes as set in the
     * "--processes" option. Each of the child processes receives a segment of
     * items of the processed data set and terminates. As long as there is data
     * left to process, new child processes are spawned automatically.
     *
     * @return int<0,255>
     */
    private function executeMainProcess(
        ParallelizationInput $parallelizationInput,
        InputInterface $input,
        OutputInterface $output,
        Logger $logger
    ): int {
        ($this->runBeforeFirstCommand)($input, $output);

        $batchSize = $parallelizationInput->getBatchSize() ?? $this->batchSize;
        $desiredSegmentSize = $parallelizationInput->getSegmentSize() ?? $this->segmentSize;

        $itemIterator = ChunkedItemsIterator::fromItemOrCallable(
            $parallelizationInput->getItem(),
            fn () => ($this->fetchItems)($input),
            $batchSize,
        );

        $numberOfItems = $itemIterator->getNumberOfItems();

        $shouldSpawnChildProcesses = !$parallelizationInput->shouldBeProcessedInMainProcess();

        $configuration = Configuration::create(
            $shouldSpawnChildProcesses,
            $numberOfItems,
            $parallelizationInput->getNumberOfProcesses(),
            $desiredSegmentSize,
            $batchSize,
        );

        $numberOfProcesses = $configuration->getNumberOfProcesses();
        $segmentSize = $configuration->getSegmentSize();
        $itemName = ($this->getItemName)($numberOfItems);

        $logger->logConfiguration(
            $configuration,
            $batchSize,
            $numberOfItems,
            $itemName,
            $shouldSpawnChildProcesses,
        );

        $logger->logStart($numberOfItems);

        if ($shouldSpawnChildProcesses) {
            $exitCode = $this
                ->createProcessLauncher(
                    $segmentSize,
                    $numberOfProcesses,
                    $input,
                    $logger,
                )
                ->run($itemIterator->getItems());
        } else {
            $exitCode = $this->processItems(
                $itemIterator,
                $input,
                $output,
                $logger,
                static fn () => $logger->logAdvance(),
            );
        }

        $logger->logFinish($itemName);

        ($this->runAfterLastCommand)($input, $output);

        return $exitCode;
    }

    /**
     * Executes the child process.
     *
     * This method reads the items from the standard input that the main process
     * piped into the process. These items are passed to runSingleCommand() one
     * by one.
     *
     * @return 0|positive-int
     */
    private function executeChildProcess(
        ParallelizationInput $parallelizationInput,
        InputInterface $input,
        OutputInterface $output,
        Logger $logger
    ): int {
        $itemIterator = ChunkedItemsIterator::fromStream(
            $this->childSourceStream,
            $parallelizationInput->getBatchSize() ?? $this->batchSize,
        );

        $progressSymbol = $this->progressSymbol;

        return $this->processItems(
            $itemIterator,
            $input,
            $output,
            $logger,
            static fn () => $output->write($progressSymbol),
        );
    }

    /**
     * @param callable():void $advance
     *
     * @return int<0,255>
     */
    private function processItems(
        ChunkedItemsIterator $itemIterator,
        InputInterface $input,
        OutputInterface $output,
        Logger $logger,
        callable $advance
    ): int {
        $exitCode = 0;

        foreach ($itemIterator->getItemChunks() as $items) {
            ($this->runBeforeBatch)($input, $output, $items);

            foreach ($items as $item) {
                $exitCode += $this->runTolerantSingleCommand($item, $input, $output, $logger);

                $advance();
            }

            ($this->runAfterBatch)($input, $output, $items);
        }

        return min($exitCode, 255);
    }

    /**
     * @return 0|positive-int
     */
    private function runTolerantSingleCommand(
        string $item,
        InputInterface $input,
        OutputInterface $output,
        Logger $logger
    ): int {
        try {
            ($this->runSingleCommand)($item, $input, $output);

            return 0;
        } catch (Throwable $throwable) {
            return $this->errorHandler->handleError($item, $throwable, $logger);
        }
    }

    /**
     * @param int<1,max> $segmentSize
     * @param int<1,max> $numberOfProcesses
     */
    private function createProcessLauncher(
        int $segmentSize,
        int $numberOfProcesses,
        InputInterface $input,
        Logger $logger
    ): ProcessLauncher {
        return $this->processLauncherFactory->create(
            $this->childCommandFactory->createChildCommand($input),
            $this->workingDirectory,
            $this->extraEnvironmentVariables,
            $numberOfProcesses,
            $segmentSize,
            $logger,
            fn (int $index, ?int $pid, string $type, string $buffer) => $this->processChildOutput(
                $index,
                $pid,
                $type,
                $buffer,
                $logger,
            ),
            $this->processTick,
        );
    }

    /**
     * Called whenever data is received in the main process from a child process.
     *
     * @param positive-int|0 $index  Index of the process amoung the list of running processes.
     * @param int|null       $pid    The child process PID. It can be null if the process is no
     *                               longer running.
     * @param string         $type   The type of output: "out" or "err".
     * @param string         $buffer The received data.
     */
    private function processChildOutput(
        int $index,
        ?int $pid,
        string $type,
        string $buffer,
        Logger $logger
    ): void {
        $progressSymbol = $this->progressSymbol;
        $charactersCount = mb_substr_count($buffer, $progressSymbol);

        // Display unexpected output
        if ($charactersCount !== mb_strlen($buffer)) {
            $logger->logUnexpectedChildProcessOutput(
                $index,
                $pid,
                $type,
                $buffer,
                $progressSymbol,
            );
        }

        $logger->logAdvance($charactersCount);
    }

    private static function validateBatchSize(int $batchSize): void
    {
        Assert::greaterThan(
            $batchSize,
            0,
            sprintf(
                'Expected the batch size to be 1 or greater. Got "%s".',
                $batchSize,
            ),
        );
    }

    private static function validateSegmentSize(int $segmentSize): void
    {
        Assert::greaterThan(
            $segmentSize,
            0,
            sprintf(
                'Expected the segment size to be 1 or greater. Got "%s".',
                $segmentSize,
            ),
        );
    }

    private static function validateProgressSymbol(string $progressSymbol): void
    {
        $symbolLength = mb_strlen($progressSymbol);

        Assert::same(
            1,
            $symbolLength,
            sprintf(
                'Expected the progress symbol length to be 1. Got "%s" for "%s".',
                $symbolLength,
                $progressSymbol,
            ),
        );
    }
}
