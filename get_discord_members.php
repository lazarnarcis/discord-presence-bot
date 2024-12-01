<?php
$lockFile = '/tmp/get_discord_members.lock';
if (file_exists($lockFile)) {
    echo "Script already running. Exiting...\n";
    exit;
}

file_put_contents($lockFile, getmypid());

require 'vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

use Discord\Discord;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

// Setup Logger
$log = new Logger('discord_member_fetch');
$log->pushHandler(new StreamHandler(__DIR__ . '/bot.log', Logger::DEBUG));

try {
    $discord = new Discord([
        'token' => $_ENV['discord_token'],
    ]);

    $mysqli = new mysqli($_ENV['host_db'], $_ENV['username_db'], $_ENV['password_db'], $_ENV['database_db'], $_ENV['port_db']);
    if ($mysqli->connect_error) {
        throw new Exception("Database connection failed: " . $mysqli->connect_error);
    }

    $discord->on('ready', function ($discord) use ($mysqli, $log) {
        $log->info("Fetching all Discord members...");

        try {
            $guild = $discord->guilds->first();

            if (!$guild) {
                throw new Exception("Could not retrieve the guild. Check bot permissions.");
            }

            $guild->members->freshen()->done(function ($members) use ($mysqli, $log) {
                // Clear the existing table
                $query = "DELETE FROM discord_members";
                $mysqli->query($query);

                foreach ($members as $member) {
                    $userId = $mysqli->real_escape_string($member->id);
                    $username = $mysqli->real_escape_string($member->user->username);
                    $nickname = $mysqli->real_escape_string($member->nick ?? '');
                    $roles = implode(',', array_map(fn($role) => $role->name, $member->roles->toArray()));

                    $query = "INSERT INTO discord_members (user_id, username, nickname, roles) 
                              VALUES ('$userId', '$username', '$nickname', '$roles')";
                    if (!$mysqli->query($query)) {
                        $log->error("Failed to insert member $username: " . $mysqli->error);
                    }

                    $query = "select * from users where discord_user_id='$userId'";
                    $result1 = $mysqli->query($query);
                    if (!$result1) {
                        $log->error("Failed to update table users with $username: " . $mysqli->error);
                    } else {
                        $query2 = "UPDATE users SET roles='".json_encode(explode(",",$roles))."' where discord_user_id='$userId'";
                        $mysqli->query($query2);
                    }
                } 

                $log->info("Successfully fetched and inserted " . count($members) . " members into the database.");
            }, function ($e) use ($log) {
                $log->error("Error fetching members: " . $e->getMessage());
            });
        } catch (Exception $e) {
            $log->error("Failed to fetch members: " . $e->getMessage());
        }
    });
    unlink($lockFile);

    $discord->run();
} catch (Exception $e) {
    $log->error("Script encountered a fatal error: " . $e->getMessage());
}
