<?php
$env = parse_ini_file('.env');
$localDbHost = $env["LOCAL_DB_HOST"];
$localDbUser = $env["LOCAL_DB_USER"];
$localDbPassword = $env["LOCAL_DB_PASSWORD"];
$serverDbHost = $env["SERVER_DB_HOST"];
$serverDbUser = $env["SERVER_DB_USER"];
$serverDbPassword = $env["SERVER_DB_PASSWORD"];
$dumpDir = $env["DUMPS_FOLDER"];
$databases = explode(",", $env["DATABASES"]);


/* CONFIG QUESTIONS */
echo "Dry run? (No sync) (y/n): ";
$handle = fopen("php://stdin", "r");
$isDryRun = trim(fgets($handle));

if (strtolower($isDryRun) !== 'y' && strtolower($isDryRun) !== 'n') {
    die("Invalid input. Please enter 'y' or 'n'.");
}

$isDryRun = strtolower($isDryRun) === 'y';

echo "Monthly backup? (y/n): ";
$isMonthlyBackup = trim(fgets($handle));

if (strtolower($isMonthlyBackup) !== 'y' && strtolower($isMonthlyBackup) !== 'n') {
    die("Invalid input. Please enter 'y' or 'n'.");
}

$isMonthlyBackup = strtolower($isMonthlyBackup) === 'y';

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

$handle = fopen("php://stdin", "r");
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


/* SOURCE/TARGET SELECTION */


echo "\n\nPlease select the source host for the database operation:\n";
echo "[1] Local Host ($localDbHost)\n";
echo "[2] Server Host ($serverDbHost)\n";

echo "\nEnter the number corresponding to the source host:\n";
$sourceHostInput = trim(fgets($handle));
$sourceHost = '';
$sourceUser = '';
$sourcePassword = '';

switch ($sourceHostInput) {
    case '1':
        $sourceHost = $localDbHost;
        $sourceUser = $localDbUser;
        $sourcePassword = $localDbPassword;
        break;
    case '2':
        $sourceHost = $serverDbHost;
        $sourceUser = $serverDbUser;
        $sourcePassword = $serverDbPassword;
        break;
    default:
        echo "\nInvalid selection. Please run the script again and choose a valid source host.\n";
        exit(1);
}

// Select the other host option depending on what was selected as the source
$targetHost = '';
$targetUser = '';
$targetPassword = '';

switch ($sourceHost) {
    case $serverDbHost:
        $targetHost = $localDbHost;
        $targetUser = $localDbUser;
        $targetPassword = $localDbPassword;
        break;
    case $localDbHost:
        $targetHost = $serverDbHost;
        $targetUser = $serverDbUser;
        $targetPassword = $serverDbPassword;
        break;
    default:
        echo "\nInvalid selection. Please run the script again and choose a valid target host.\n";
        exit(1);
}

echo "\n\nYou have selected the following configurations:\n";
echo "Source Host: $sourceHost\n";
echo "Target Host: $targetHost\n";

echo "\nProceeding with the database operation...\n";

/* END SOURCE/TARGET SELECTION */


/* BACKUP FILE CREATION */
$currentDate = date("Ymd");
$sourcefileSuffix = $sourceHost === $localDbHost ? "local" : "server";
$targetfileSuffix = $targetHost === $localDbHost ? "local" : "server";
$operationDumpDir = $dumpDir . "/" . $sourcefileSuffix . "_to_" . $targetfileSuffix . "_" . $currentDate . "/";

if (!is_dir($operationDumpDir)) {
    echo "\nCreating sync directory...\n";
    mkdir($operationDumpDir);
}

$sourceBackupFiles = [];

foreach ($selectedDatabases as $database) {
    $currentTimestamp = date("YmdHis");
    $sourceBackupFile = $operationDumpDir . $sourcefileSuffix . "_backup_" . $database . "_" . $currentTimestamp . ".sql";
    $targetBackupFile = $operationDumpDir . $targetfileSuffix . "_backup_" . $database . "_" . $currentTimestamp .  ".sql";

    $sourceBackupFiles[$database] =  $sourceBackupFile;

    function generateBackupFileCommand(string $host, string $user, string $password, string $file): string
    {
        global $database;
        $escapedPassword = str_replace(" ", "!", escapeshellarg($password));
        return "\"C:\\Program Files\\MySQL\\MySQL Workbench 8.0 CE\\mysqldump.exe\" --host=$host --port=3306 --default-character-set=utf8 --user=$user --password=$escapedPassword --protocol=tcp --skip-triggers --skip-column-statistics $database > $file";
    }

    $sourceBackupCmd = generateBackupFileCommand($sourceHost, $sourceUser, $sourcePassword, $sourceBackupFile);
    $targetBackupCmd = generateBackupFileCommand($targetHost, $targetUser, $targetPassword, $targetBackupFile);

    shell_exec($sourceBackupCmd);
    shell_exec($targetBackupCmd);
}
/* END BACKUP FILE CREATION */

/* SYNC FILE CREATION */
$jsonInfo = file_get_contents("db-info.json");

if ($jsonInfo === false) {
    die("Failed to read JSON file.");
}

$dbInfo = json_decode($jsonInfo, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    die("Failed to decode JSON: " . json_last_error_msg());
}

foreach ($sourceBackupFiles as $database => $sourceSyncFile) {
    if (isset($dbInfo[$database])) {
        $jsonEntry = $dbInfo[$database];
        $replacementUrl = "";
        $searchUrl = "";
        $newSyncFile = str_replace($sourcefileSuffix . "_backup", $sourcefileSuffix . "_to_" . $targetfileSuffix, $sourceSyncFile);

        if ($sourcefileSuffix === "local") {
            $replacementUrl = $jsonEntry["prodUrl"];
            $searchUrl = $jsonEntry["localUrl"];
        } else {
            $replacementUrl = $jsonEntry["localUrl"];
            $searchUrl = $jsonEntry["prodUrl"];
        }

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
/* END SYNC */
