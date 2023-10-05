# BunnyDNS - PostgreSQL (PowerDNS) Synchronization

This script synchronizes DNS records from a PowerDNS instance using a PostgreSQL backend to Bunny.net's DNS service. If you're using PowerDNS with PostgreSQL and wish to mirror your DNS records to Bunny.net, this script is for you.

## Prerequisites
- PHP
- `guzzlehttp/guzzle` PHP library
- PowerDNS instance with PostgreSQL as the backend having a table named `records`

## Configuration

### Constants

Set the base URL for Bunny.net API:
```php
const BUNNY_BASE_URL = 'https://api.bunny.net/dnszone/';
```

### PostgreSQL Configuration

Modify the script to connect to your PostgreSQL database using the configuration:

```php
$host = '10.0.0.1';
$db = 'powerdns';
$user = 'sync';
$pass = 'pw';
```

### Bunny.net API Access Key

Update the `AccessKey` in the Guzzle Client to your Bunny.net API AccessKey:

```php
'headers' => [
    'AccessKey' => 'BUNNYDNSAPI',
    ...
]
```

## Usage

Execute the script to start the synchronization:

```bash
php path_to_script.php
```

As the script runs, it will:

1. Retrieve all the domains from Bunny.net.
2. Pull all domain records from the PowerDNS instance on PostgreSQL.
3. Match and synchronize records from PostgreSQL to Bunny.net.

Real-time feedback is provided for actions taken, such as processing domains, skipping existing records, and any errors encountered.

## Important Notes

1. The script assumes a specific structure for your PostgreSQL records. The `records` table in PostgreSQL should have columns: `name`, `type`, `ttl`, `content`, and `prio`.
2. Default configurations are set for new domains added to Bunny.net (e.g., custom nameservers and SOA email). Please review and adjust them if necessary.
3. Exception handling ensures that issues encountered during synchronization are reported, but they won't halt the entire process. Review the logs to ensure successful sync.
