<?php
/* SETUP */
$env = parse_ini_file('.env');
$dumpDir = $env["DUMPS_FOLDER"];
$databases = explode(",", $env["DATABASES"]);
$environments = explode(",", $env["ENVIRONMENTS"]);

$jsonInfo = file_get_contents("db-info.json");

if ($jsonInfo === false) {
    die("Failed to read JSON file.");
}

$dbInfo = json_decode($jsonInfo, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    die("Failed to decode JSON: " . json_last_error_msg());
}

function generateBackupFileCommand(string $env, string $file): string
{
    global $database;
    global $dbInfo;

    $host = $dbInfo[$database][$env]["host"];
    $user = $dbInfo[$database][$env]["user"];
    $password = $dbInfo[$database][$env]["password"];
    $escapedPassword = str_replace(" ", "!", escapeshellarg($password));

    return "\"C:\\Program Files\\MySQL\\MySQL Workbench 8.0 CE\\mysqldump.exe\" --host=$host --port=3306 --default-character-set=utf8 --user=$user --password=$escapedPassword --protocol=tcp --skip-triggers --skip-column-statistics $database > $file";
}
/* END SETUP */


/* CONFIG QUESTIONS */
$handle = fopen("php://stdin", "r");

echo "Monthly backup? (y/n): ";
$isMonthlyBackup = trim(fgets($handle));

if (strtolower($isMonthlyBackup) !== 'y' && strtolower($isMonthlyBackup) !== 'n') {
    die("Invalid input. Please enter 'y' or 'n'.");
}

$isMonthlyBackup = strtolower($isMonthlyBackup) === 'y';
$isDryRun = false;

if (!$isMonthlyBackup) {
    echo "Dry run? (No sync) (y/n): ";
    $isDryRun = trim(fgets($handle));

    if (strtolower($isDryRun) !== 'y' && strtolower($isDryRun) !== 'n') {
        die("Invalid input. Please enter 'y' or 'n'.");
    }

    $isDryRun = strtolower($isDryRun) === 'y';
}

echo "\n\nConfiguration:\n";
echo "Dry Run: " . ($isDryRun ? "Yes" : "No") . "\n";
echo "Monthly Backup: " . ($isMonthlyBackup ? "Yes" : "No") . "\n\n";
/* END CONFIG QUESTIONS */


/* DATABASE SELECTION  */
echo "Please select at least one database from the list below:\n";

foreach ($databases as $index => $database) {
    echo "[" . ($index + 1) . "] " . $database . "\n";
}

echo "\n\nEnter the numbers corresponding to the databases you want to select, separated by commas:\n";

$input = trim(fgets($handle));

$selectedIndices = explode(",", $input);
$selectedDatabases = [];

foreach ($selectedIndices as $index) {
    $index = trim($index) - 1; // Adjusting index to match array
    if (isset($databases[$index])) {
        $selectedDatabases[] = $databases[$index];
    }
}

if (count($selectedDatabases) < 1) {
    echo "\n\nYou must select at least one database.\n";
    exit(1);
}

echo "\n\nYou have selected the following databases:\n";
foreach ($selectedDatabases as $database) {
    echo "- $database\n";
}
/* END DATABASE SELECTION  */


/* MONTHLY BACKUPS */
if ($isMonthlyBackup) {
    $currentDate = date("Ymd");

    $dumpDir = $dumpDir . "\\MonthlyBackups";


    $operationDumpDir = $dumpDir . "/backups_" . $currentDate . "/";

    if (!is_dir($operationDumpDir)) {
        echo "\nCreating sync directory...\n";
        mkdir($operationDumpDir);
    }

    foreach ($selectedDatabases as $database) {
        $currentTimestamp = date("YmdHis");

        foreach ($environments as $environment) {
            $envBackupFile = $operationDumpDir . $environment . "_backup_" . $database . "_" . $currentTimestamp . ".sql";
            $backupCmd = generateBackupFileCommand($environment, $envBackupFile);
            shell_exec($backupCmd);
        }
    }

    echo "\nBackup File creation complete. Since you selected monthly backup, this process will now be terminated.\n";
    exit(0);
}
/* END MONTHLY BACKUPS */


/* SOURCE/TARGET SELECTION */
echo "\n\nPlease select the source environment for the database operation:\n";
for ($i = 0; $i < count($environments); $i++) {
    echo "[" . ($i + 1) . "] " . $environments[$i] . "\n";
}

echo "\nEnter the number corresponding to the source environment:\n";
$sourceEnvInput = trim(fgets($handle));

if (!isset($environments[intval($sourceEnvInput) - 1])) {
    die("Invalid environment input.");
}

$sourceEnv = $environments[intval($sourceEnvInput) - 1];

echo "\n\nPlease select the target environment for the database operation:\n";
for ($i = 0; $i < count($environments); $i++) {
    if ($environments[$i] === $sourceEnv) {
        continue;
    }
    echo "[" . ($i + 1) . "] " . $environments[$i] . "\n";
}

echo "\nEnter the number corresponding to the target environment:\n";
$targetEnvInput = trim(fgets($handle));

if (!isset($environments[intval($targetEnvInput) - 1]) || $environments[intval($targetEnvInput) - 1] === $sourceEnv) {
    die("Invalid environment input.");
}

$targetEnv = $environments[intval($targetEnvInput) - 1];

echo "\n\nYou have selected the following configurations:\n";
echo "Source Environment: $sourceEnv\n";
echo "Target Environment: $targetEnv\n";
echo "\nProceeding with the database operation...\n";
/* END SOURCE/TARGET SELECTION */


/* BACKUP FILE CREATION */
$currentDate = date("Ymd");
$operationDumpDir = $dumpDir . "/" . $sourceEnv . "_to_" . $targetEnv . "_" . $currentDate . "/";

if (!is_dir($operationDumpDir)) {
    echo "\nCreating sync directory...\n";
    mkdir($operationDumpDir);
}

$sourceBackupFiles = [];

foreach ($selectedDatabases as $database) {
    $currentTimestamp = date("YmdHis");
    $sourceBackupFile = $operationDumpDir . $sourceEnv . "_backup_" . $database . "_" . $currentTimestamp . ".sql";
    $targetBackupFile = $operationDumpDir . $targetEnv . "_backup_" . $database . "_" . $currentTimestamp .  ".sql";

    $sourceBackupFiles[$database] =  $sourceBackupFile;


    $sourceBackupCmd = generateBackupFileCommand($sourceEnv, $sourceBackupFile);
    $targetBackupCmd = generateBackupFileCommand($targetEnv, $targetBackupFile);

    shell_exec($sourceBackupCmd);
    shell_exec($targetBackupCmd);
}
/* END BACKUP FILE CREATION */


/* SYNC FILE CREATION */
$syncFiles = [];

foreach ($sourceBackupFiles as $database => $sourceSyncFile) {
    if (isset($dbInfo[$database])) {
        $replacementUrl = $dbInfo[$database][$targetEnv]["url"];
        $searchUrl = $dbInfo[$database][$sourceEnv]["url"];
        $newSyncFile = str_replace($sourceEnv . "_backup", $sourceEnv . "_to_" . $targetEnv, $sourceSyncFile);

        echo "\nStarting URL Replacement...\n";

        $fileContents = file_get_contents($sourceSyncFile);

        if ($fileContents === false) {
            die("Failed to read the file.");
        }

        // Replace all instances of the old URL with the new URL
        $updatedContents = str_replace($searchUrl, $replacementUrl, $fileContents);

        // Write the updated contents back to the file
        $result = file_put_contents($newSyncFile, $updatedContents);

        if ($result === false) {
            die("Failed to write to the file.");
        }

        $syncFiles[$database] = $newSyncFile;

        echo "\nURL replacement completed successfully.\n";
    } else {
        die("No JSON entry found for database: $database\n");
    }
}

/* END SYNC FILE CREATION */


/* SYNC */
if ($isDryRun) {
    echo "\nFile creation complete. Since you selected dry run, this process will now be terminated.\n";
    exit(0);
}

foreach ($syncFiles as $database => $syncFile) {
    $host = $dbInfo[$database][$targetEnv]["host"];
    $user = $dbInfo[$database][$targetEnv]["user"];
    $password = $dbInfo[$database][$targetEnv]["password"];
    $escapedPassword = str_replace(" ", "!", escapeshellarg($password));
    $syncCmd  = "\"C:\\Program Files\\MySQL\\MySQL Workbench 8.0 CE\\mysql.exe\" --host=$host --port=3306 --default-character-set=utf8 --user=$user --password=$escapedPassword --comments --database=$database  <$syncFile";
    echo "\nSyncing $sourceEnv to $targetEnv, Database: $database\n";
    shell_exec($syncCmd);
}

echo "\n Synchronization is complete. This process will now be terminated.\n";
exit(0);
/* END SYNC */
