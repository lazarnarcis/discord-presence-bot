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

    $discord->on(Event::VOICE_STATE_UPDATE, function ($voiceState) use ($discord, $mysqli, &$voiceStates, &$deafenTimers, &$idleTimers) {
        $userId = $voiceState->user_id;
        $channelId = $voiceState->channel_id;
        $sessionId = $voiceState->session_id;
        $username = $voiceState->user->username;
        $deafened = $voiceState->self_deaf;

        if ($channelId && $sessionId) {
            if (!isset($voiceStates[$userId])) {
                foreach ($voiceStates as $existingUserId => $existingChannelId) {
                    if ($existingChannelId === $channelId && $existingUserId !== $userId) {
                        $user = $discord->users->get('id', $existingUserId);
                        $user->sendMessage("$username has joined your voice channel.");
                    }
                }

                $currentTime = time();
                $query = "INSERT INTO voice_presence (user_id, join_time, channel_id, username) VALUES ('$userId', '$currentTime', '$channelId', '$username')";
                $mysqli->begin_transaction();
                $mysqli->query($query);
                $mysqli->commit();

                $voiceStates[$userId] = $channelId;

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
            $query = "UPDATE voice_presence SET leave_time = '$currentTime', total_time = total_time + ('$currentTime' - join_time) WHERE user_id = '$userId' AND leave_time IS NULL";
            $mysqli->begin_transaction();
            $mysqli->query($query);
            $mysqli->commit();

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

    $discord->loop->addPeriodicTimer(60, function () use ($discord, &$deafenTimers, &$voiceStates, &$idleTimers) {
        $currentTime = time();

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
                $user = $discord->users->get('id', $userId);
                $user->sendMessage("You are now Idle. You will be disconnected after 10 minutes of being idle!");
            } elseif ($currentTime - $startTime >= 1200) {
                if (isset($voiceStates[$userId])) {
                    $guild = $discord->guilds->first();
                    $member = $guild->members->get('id', $userId);
                    $member->moveMember(null);
                    $user = $discord->users->get('id', $userId);
                    $user->sendMessage("You have been disconnected from the voice channel because you were AFK!");
                    unset($idleTimers[$userId]);
                }
            }
        }
    });
});

$discord->run();
?>