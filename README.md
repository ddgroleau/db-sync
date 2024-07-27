# Database Backup and Synchronization CLI Utility

This CLI utility is designed to perform database backups and synchronization between different environments. It supports monthly backups, dry run mode, and synchronization with URL replacements. The script reads configuration from a `.env` file and a `db-info.json` file.

## Requirements

- PHP
- MySQL Workbench 8.0 CE

## Setup

1. **Create a `.env` file** in the same directory as the script with the following variables:

   ```env
   DATABASES=db1,db2,db3
   ENVIRONMENTS=dev,staging,prod
   DUMPS_FOLDER=/path/to/dumps
   ```

2. **Create a `db-info.json` file** in the same directory as the script with the following format:
   ```json
   {
     "db-name": {
       "dev": {
         "url": "http://dev.example.com",
         "host": "localhost",
         "user": "devuser",
         "password": "devpassword"
       },
       "staging": {
         "url": "http://staging.example.com",
         "host": "localhost",
         "user": "staginguser",
         "password": "stagingpassword"
       },
       "prod": {
         "url": "http://example.com",
         "host": "localhost",
         "user": "produser",
         "password": "prodpassword"
       }
     }
   }
   ```

## Usage

Run the script using PHP from the command line:

```sh
php db-sync.php
```

### Configuration Questions

- **Monthly Backup**: You will be prompted to choose whether you want to perform a monthly backup (`y` or `n`).
- **Dry Run**: If you choose not to perform a monthly backup, you will be asked if you want to perform a dry run (`y` or `n`).
- **Database Selection**: You will be prompted to select one or more databases from the list.
- **Source Environment**: Select the source environment for the database operation.
- **Target Environment**: Select the target environment for the database operation.

### Monthly Backup

If monthly backup is selected, the script will create a backup of the selected databases in the specified dump directory and terminate.

### Backup File Creation

If monthly backup is not selected, the script will proceed with creating backup files for the source and target environments.

### Sync File Creation

The script will replace URLs in the source backup files with the corresponding URLs from the target environment.

### Synchronization

If dry run is not selected, the script will synchronize the databases using the generated sync files.

## Notes

- Ensure MySQL Workbench 8.0 CE is installed and accessible via the specified path in the script.
- The script uses `escapeshellarg` to handle passwords with special characters. Ensure passwords in `db-info.json` are properly formatted.
- The dump directory specified in `.env` should be writable by the script.

## Example `.env` File

```env
DATABASES=db1,db2,db3
ENVIRONMENTS=dev,staging,prod
DUMPS_FOLDER=/path/to/dumps
```

## Example `db-info.json` File

```json
{
  "db1": {
    "dev": {
      "url": "http://dev.example.com",
      "host": "localhost",
      "user": "devuser",
      "password": "devpassword"
    },
    "staging": {
      "url": "http://staging.example.com",
      "host": "localhost",
      "user": "staginguser",
      "password": "stagingpassword"
    },
    "prod": {
      "url": "http://example.com",
      "host": "localhost",
      "user": "produser",
      "password": "prodpassword"
    }
  }
}
```

For any questions or issues, please refer to the script comments or contact the author.
