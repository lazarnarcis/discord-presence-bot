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
        $channelId = $voiceState->channel_id;
        $sessionId = $voiceState->session_id;
        $username = $voiceState->user->username;
        $deafened = $voiceState->self_deaf;
        $currentDate = date('Y-m-d');

        $channelName = null;
        if ($channelId) {
            $channel = $discord->getChannel($channelId);
            $channelName = $channel ? $channel->name : null;
        }

        if ($channelId && $sessionId) {
            if (!isset($voiceStates[$userId])) {
                foreach ($voiceStates as $existingUserId => $existingData) {
                    if ($existingData['channel_id'] === $channelId && $existingUserId !== $userId) {
                        $user = $discord->users->get('id', $existingUserId);
                        $user->sendMessage("$username has joined your voice channel.");
                    }
                }

                $currentTime = time();
                $query = "SELECT * FROM voice_presence WHERE user_id = '$userId' AND date = '$currentDate' AND closed = 0";
                $result = $mysqli->query($query);

                if ($result->num_rows == 0) {
                    $query = "INSERT INTO voice_presence (user_id, date, total_time, channel_id, channel_name, username, closed) VALUES ('$userId', '$currentDate', 0, '$channelId', '$channelName', '$username', 0)";
                    $mysqli->query($query);
                }

                $voiceStates[$userId] = [
                    'channel_id' => $channelId,
                    'join_time' => $currentTime,
                    'idle_notified' => false
                ];

                if (!isset($idleTimers[$userId])) {
                    $idleTimers[$userId] = time();
                }
            }

            if ($deafened) {
                if (!isset($deafenTimers[$userId])) {
                    $user = $discord->users->get('id', $userId);
                    $user->sendMessage("You have deafened yourself. If you remain deafened for 45 minutes, you will be removed from the voice channel.");
                    $deafenTimers[$userId] = time();
                }
            } else {
                unset($deafenTimers[$userId]);
            }
        } elseif (!$channelId && isset($voiceStates[$userId])) {
            $currentTime = time();
            $joinTime = $voiceStates[$userId]['join_time'];
            $totalTime = $currentTime - $joinTime;
            $query = "UPDATE voice_presence SET total_time = total_time + '$totalTime', closed = 1 WHERE user_id = '$userId' AND date = '$currentDate' AND closed = 0";
            $mysqli->query($query);

            unset($voiceStates[$userId]);
            unset($deafenTimers[$userId]);
            unset($idleTimers[$userId]);
        } elseif (isset($voiceStates[$userId])) {
            if ($deafened && !isset($deafenTimers[$userId])) {
                $user = $discord->users->get('id', $userId);
                $user->sendMessage("You have deafened yourself. If you remain deafened for 45 minutes, you will be removed from the voice channel.");
                $deafenTimers[$userId] = time();
            } elseif (!$deafened && isset($deafenTimers[$userId])) {
                unset($deafenTimers[$userId]);
            }

            if (!isset($deafenTimers[$userId])) {
                $idleTimers[$userId] = time();
            }
        }
    });

    $discord->loop->addPeriodicTimer(3, function () use ($discord, $mysqli, &$voiceStates, &$deafenTimers, &$idleTimers) {
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
?>