<?php

// APP_ENV=testing php8.4 scripts/gate-template-db.php
//
// Runs on the box before the parallel gate (scripts/gate-backend.sh). One real
// migrate:fresh, every migration from zero, on the gate's base test database;
// then that database, schema AND rows, is written to database/schema/mysql-schema.sql.
//
// Why: every parallel worker migrates its own database on its first test, and
// a migrate:fresh here is ~1,700 DDL statements (534 migrations; Laravel adds
// each index and foreign key as its own ALTER). The box's disk, not its CPU,
// bounds DDL, so eight workers migrating at once took 13 minutes before the
// first test ran. With this file present, Laravel's migrate:fresh loads it in
// one `mysql` call (one CREATE per table) and then runs only migrations the file
// does not record: none. Tests that rebuild the schema mid-run (a DDL statement
// ends RefreshDatabase's transaction) load it too.
//
// Rows, not only the schema (`schema:dump` writes the schema and the migrations
// table): 77 migrations insert data (permissions, defaults), and a worker that
// loaded structure alone would start from a different database than one that
// migrated. The file is the migrated database itself, so the two are the same.
//
// The file is generated per run and is not in git; the gate's archive brings a
// fresh database/ every run, so a stale one cannot outlive its tree.
use Illuminate\Contracts\Console\Kernel;
use Symfony\Component\Process\Process;
use Tests\Support\DatabaseSafety;

$root = dirname(__DIR__);
$dump = $root.'/database/schema/mysql-schema.sql';
// Never let an old file stand in for the migrations this run must execute.
@unlink($dump);

require $root.'/vendor/autoload.php';

// The same environment the tests boot with: phpunit.xml's <env> values, which,
// like PHPUnit, do not override a variable that is already set.
foreach (simplexml_load_file($root.'/phpunit.xml')->php->env ?? [] as $env) {
    $name = (string) $env['name'];
    if (getenv($name) === false) {
        putenv($name.'='.$env['value']);
        $_ENV[$name] = $_SERVER[$name] = (string) $env['value'];
    }
}

$app = require $root.'/bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

$name = config('database.default');
$db = config("database.connections.{$name}");
if (! $app->environment('testing') || ($db['driver'] ?? null) !== 'mysql' || $name !== 'mysql') {
    fwrite(STDERR, "gate-template-db: refusing, this is for the mysql test connection only\n");
    exit(1);
}
DatabaseSafety::assertSafe($db, false, false);

if ($kernel->call('migrate:fresh', ['--force' => true]) !== 0) {
    fwrite(STDERR, $kernel->output());
    exit(1);
}

@mkdir(dirname($dump), 0775, true);
$process = new Process([
    'mysqldump', '--host='.$db['host'], '--port='.$db['port'], '--user='.$db['username'],
    '--single-transaction', '--no-tablespaces', '--skip-add-locks', '--skip-comments',
    '--tz-utc', '--column-statistics=0', '--set-gtid-purged=OFF', '--routines', '--triggers',
    '--result-file='.$dump.'.part', $db['database'],
], null, ['MYSQL_PWD' => (string) $db['password']], null, 600);
if ($process->run() !== 0) {
    fwrite(STDERR, 'gate-template-db: mysqldump failed: '.$process->getErrorOutput());
    exit(1);
}

// Laravel loads this file through the `mysql` client, which does not run the
// connection's PDO init command. On the box that command is what keeps the gate
// out of production's binlog (`SET SESSION sql_log_bin=0`), so it leads the file.
$init = $db['options'][PDO\Mysql::ATTR_INIT_COMMAND] ?? '';
$sql = file_get_contents($dump.'.part');
file_put_contents($dump, ($init !== '' ? rtrim($init, ';').";\n" : '').$sql);
unlink($dump.'.part');

$tables = substr_count($sql, 'CREATE TABLE ');
echo "template database: {$db['database']} migrated from zero, {$tables} tables written to database/schema/mysql-schema.sql\n";
