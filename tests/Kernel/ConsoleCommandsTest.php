<?php

declare(strict_types=1);

namespace Tests\Kernel;

use PDO;
use PHPUnit\Framework\TestCase;
use Silviooosilva\CacheerPhp\Console\CacheerContext;
use Silviooosilva\CacheerPhp\Console\CommandInput;
use Silviooosilva\CacheerPhp\Console\CommandOutput;
use Silviooosilva\CacheerPhp\Console\Commands\ClearCommand;
use Silviooosilva\CacheerPhp\Console\Commands\DoctorCommand;
use Silviooosilva\CacheerPhp\Console\Commands\MigrateCommand;
use Silviooosilva\CacheerPhp\Console\Commands\PruneCommand;
use Silviooosilva\CacheerPhp\Console\Commands\StatsCommand;
use Silviooosilva\CacheerPhp\Kernel\Key;
use Silviooosilva\CacheerPhp\Kernel\Ttl;
use Silviooosilva\CacheerPhp\Stores\ArrayStore;
use Silviooosilva\CacheerPhp\Stores\Support\DatabaseStoreSchema;
use Tests\Support\FakeClock;

final class ConsoleCommandsTest extends TestCase
{
    private FakeClock $clock;

    private ArrayStore $store;

    private CacheerContext $context;

    protected function setUp(): void
    {
        $this->clock = new FakeClock();
        $this->store = new ArrayStore($this->clock);
        $this->context = new CacheerContext($this->store);
    }

    private function execute(object $command, CommandInput $input, ?CacheerContext $context): array
    {
        $output = new CommandOutput();
        $code = $command->run($input, $output, $context);

        return [$code, $output->data()];
    }

    public function testDoctorReportsHealthAndRunsWithoutConfig(): void
    {
        [$code, $data] = $this->execute(new DoctorCommand(), new CommandInput(), null);

        self::assertSame(0, $code);
        self::assertTrue($data['healthy']);
        self::assertContains('config', array_column($data['checks'], 'name'));
    }

    public function testStatsListsCapabilitiesAndEntryCount(): void
    {
        $this->store->set(Key::named('a'), 1, Ttl::forever());
        $this->store->set(Key::named('b'), 2, Ttl::forever());

        [$code, $data] = $this->execute(new StatsCommand(), new CommandInput(), $this->context);

        self::assertSame(0, $code);
        self::assertSame('ArrayStore', $data['store']);
        self::assertContains('atomic', $data['capabilities']);
        self::assertSame(2, $data['entries']);
    }

    public function testPruneDryRunDoesNotDelete(): void
    {
        $this->store->set(Key::named('gone'), 'v', Ttl::seconds(1));
        $this->clock->advance(2);

        [$code, $data] = $this->execute(new PruneCommand(), CommandInput::fromTokens(['--dry-run']), $this->context);
        self::assertSame(0, $code);
        self::assertTrue($data['dry_run']);
        self::assertNull($data['pruned']);

        [, $realData] = $this->execute(new PruneCommand(), new CommandInput(), $this->context);
        self::assertSame(1, $realData['pruned']);
    }

    public function testClearRefusesWithoutForceAndNamesItsTarget(): void
    {
        $this->store->set(Key::named('keep'), 'v', Ttl::forever());

        [$code, $data] = $this->execute(new ClearCommand(), new CommandInput(), $this->context);
        self::assertSame(1, $code);
        self::assertSame('force_required', $data['error']);
        self::assertSame('ArrayStore', $data['keyspace']);
        self::assertTrue($this->store->get(Key::named('keep'))->isHit(), 'A refused clear must not delete.');

        [$forcedCode] = $this->execute(new ClearCommand(), CommandInput::fromTokens(['--force']), $this->context);
        self::assertSame(0, $forcedCode);
        self::assertTrue($this->store->get(Key::named('keep'))->isMiss());
    }

    public function testMigrateDryRunPrintsDdlWithoutExecuting(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $context = new CacheerContext($this->store, $pdo, 'cacheer_store');

        [$code, $data] = $this->execute(new MigrateCommand(), CommandInput::fromTokens(['--dry-run']), $context);
        self::assertSame(0, $code);
        self::assertTrue($data['dry_run']);
        self::assertNotEmpty($data['statements']);

        // Nothing was created yet.
        $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
        self::assertNotContains('cacheer_store', $tables);

        // A real run creates the schema.
        [$realCode, $realData] = $this->execute(new MigrateCommand(), new CommandInput(), $context);
        self::assertSame(0, $realCode);
        self::assertTrue($realData['migrated']);
        DatabaseStoreSchema::drop($pdo, 'cacheer_store');
    }
}
