<?php
require 'vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

use Discord\Discord;
use Discord\WebSockets\Event;

$discord = new Discord([
    'token' => $_ENV['discord_token'],
]);

$mysqli = new mysqli($_ENV['host_db'], $_ENV['username_db'], $_ENV['password_db'], $_ENV['database_db'], $_ENV['port_db']);
if ($mysqli->connect_error) {
    die("Connection failed: " . $mysqli->connect_error);
}

$voiceStates = [];
$deafenTimers = [];
$idleTimers = [];

$discord->on('ready', function ($discord) use ($mysqli, &$voiceStates, &$deafenTimers, &$idleTimers) {
    echo "Bot is ready.", PHP_EOL;

    $guild = $discord->guilds->first(); 
    $query = "DELETE FROM discord_roles";
    $mysqli->query($query);
    foreach ($guild->roles as $role) {
        $roleName = $mysqli->real_escape_string($role->name);
        $query = "INSERT INTO discord_roles (name) VALUES ('$roleName')";

        $notAdd = ['@everyone', 'Member', "Development Hub"];
        if (!in_array($roleName, $notAdd)) {
            $mysqli->query($query);
        }
    }

    $discord->on(Event::VOICE_STATE_UPDATE, function ($voiceState) use ($discord, $mysqli, &$voiceStates, &$deafenTimers, &$idleTimers) {
        $userId = $voiceState->user_id;
        $newChannelId = $voiceState->channel_id;
        $username = $voiceState->user->username;
        $currentDate = date('Y-m-d');

        $newChannelName = null;
        if ($newChannelId) {
            $channel = $discord->getChannel($newChannelId);
            $newChannelName = $channel ? $channel->name : null;
        }

        $currentTime = time();

        if (!$newChannelId) {
            if (isset($voiceStates[$userId])) {
                $joinTime = $voiceStates[$userId]['join_time'];
                $previousChannelId = $voiceStates[$userId]['channel_id'];
                $totalTime = $currentTime - $joinTime;

                $query = "UPDATE voice_presence SET total_time = total_time + '$totalTime', closed = 1 
                          WHERE user_id = '$userId' AND date = '$currentDate' AND channel_id = '$previousChannelId' AND closed = 0";
                $mysqli->query($query);

                unset($voiceStates[$userId]);
                unset($idleTimers[$userId]);
                unset($deafenTimers[$userId]);
            }
            return;
        }

        if (!isset($voiceStates[$userId])) {
            $query = "INSERT INTO voice_presence (user_id, date, total_time, channel_id, channel_name, username, closed) 
                      VALUES ('$userId', '$currentDate', 0, '$newChannelId', '$newChannelName', '$username', 0)";
            $mysqli->query($query);

            $voiceStates[$userId] = [
                'channel_id' => $newChannelId,
                'join_time' => $currentTime,
                'idle_notified' => false,
            ];

            $idleTimers[$userId] = $currentTime;
        } elseif ($voiceStates[$userId]['channel_id'] !== $newChannelId) {
            $previousChannelId = $voiceStates[$userId]['channel_id'];
            $joinTime = $voiceStates[$userId]['join_time'];
            $totalTime = $currentTime - $joinTime;

            $query = "UPDATE voice_presence SET total_time = total_time + '$totalTime', closed = 1 
                      WHERE user_id = '$userId' AND date = '$currentDate' AND channel_id = '$previousChannelId' AND closed = 0";
            $mysqli->query($query);

            $query = "INSERT INTO voice_presence (user_id, date, total_time, channel_id, channel_name, username, closed) 
                      VALUES ('$userId', '$currentDate', 0, '$newChannelId', '$newChannelName', '$username', 0)";
            $mysqli->query($query);

            $voiceStates[$userId] = [
                'channel_id' => $newChannelId,
                'join_time' => $currentTime,
                'idle_notified' => false,
            ];

            $idleTimers[$userId] = $currentTime;
        }
    });

    $discord->loop->addPeriodicTimer(1, function () use ($discord, $mysqli, &$voiceStates, &$deafenTimers, &$idleTimers) {
        $currentTime = time();
        $currentDate = date('Y-m-d');

        foreach ($deafenTimers as $userId => $startTime) {
            if ($currentTime - $startTime >= 2700) {
                if (isset($voiceStates[$userId])) {
                    $guild = $discord->guilds->first();
                    $member = $guild->members->get('id', $userId);
                    $member->moveMember(null);
                    unset($deafenTimers[$userId]);
                }
            }
        }

        foreach ($idleTimers as $userId => $startTime) {
            if ($currentTime - $startTime >= 600 && ($currentTime - $startTime) < 660) {
                if (!$voiceStates[$userId]['idle_notified']) {
                    $user = $discord->users->get('id', $userId);
                    $user->sendMessage("You are now Idle. You will be disconnected after 10 minutes of being idle!");
                    $voiceStates[$userId]['idle_notified'] = true;
                }
            } elseif ($currentTime - $startTime >= 1200) {
                if (isset($voiceStates[$userId])) {
                    $guild = $discord->guilds->first();
                    $member = $guild->members->get('id', $userId);
                    $member->moveMember(null);
                    $user = $discord->users->get('id', $userId);
                    $user->sendMessage("You have been disconnected from the voice channel because you were AFK!");
                    unset($idleTimers[$userId]);
                    unset($voiceStates[$userId]);
                }
            }
        }

        foreach ($voiceStates as $userId => $state) {
            $joinTime = $state['join_time'];
            $elapsedTime = $currentTime - $joinTime;

            $query = "UPDATE voice_presence SET total_time = total_time + '$elapsedTime' WHERE user_id = '$userId' AND date = '$currentDate' AND closed = 0";
            $mysqli->query($query);
            $voiceStates[$userId]['join_time'] = $currentTime;
        }
    });
});

$discord->run();
