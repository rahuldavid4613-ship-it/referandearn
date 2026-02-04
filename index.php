<?php
// Bot configuration - Use environment variable for Render.com
define('BOT_TOKEN', getenv('BOT_TOKEN') ?: 'Place_Your_Token_Here');
define('API_URL', 'https://api.telegram.org/bot' . BOT_TOKEN . '/');
define('WEBHOOK_URL', getenv('RENDER_EXTERNAL_URL') ?: 'https://your-app-name.onrender.com');
define('USERS_FILE', 'users.json');
define('ERROR_LOG', 'error.log');

// Set timezone
date_default_timezone_set('UTC');

// Error logging
function logError($message) {
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents(ERROR_LOG, "[$timestamp] $message\n", FILE_APPEND);
    error_log($message); // Also log to PHP error log
}

// Initialize webhook
function setWebhook() {
    try {
        $url = API_URL . 'setWebhook?url=' . urlencode(WEBHOOK_URL);
        $result = file_get_contents($url);
        $response = json_decode($result, true);
        
        if ($response['ok']) {
            logError("Webhook set successfully: " . WEBHOOK_URL);
            return true;
        } else {
            logError("Failed to set webhook: " . $response['description']);
            return false;
        }
    } catch (Exception $e) {
        logError("Webhook initialization failed: " . $e->getMessage());
        return false;
    }
}

// Delete webhook (for maintenance)
function deleteWebhook() {
    try {
        $url = API_URL . 'deleteWebhook';
        file_get_contents($url);
        return true;
    } catch (Exception $e) {
        logError("Delete webhook failed: " . $e->getMessage());
        return false;
    }
}

// Data management
function loadUsers() {
    try {
        if (!file_exists(USERS_FILE)) {
            file_put_contents(USERS_FILE, json_encode([]));
        }
        return json_decode(file_get_contents(USERS_FILE), true) ?: [];
    } catch (Exception $e) {
        logError("Load users failed: " . $e->getMessage());
        return [];
    }
}

function saveUsers($users) {
    try {
        file_put_contents(USERS_FILE, json_encode($users, JSON_PRETTY_PRINT));
        return true;
    } catch (Exception $e) {
        logError("Save users failed: " . $e->getMessage());
        return false;
    }
}

// Message sending with inline keyboard
function sendMessage($chat_id, $text, $keyboard = null) {
    try {
        $params = [
            'chat_id' => $chat_id,
            'text' => $text,
            'parse_mode' => 'HTML'
        ];
        
        if ($keyboard) {
            $params['reply_markup'] = json_encode([
                'inline_keyboard' => $keyboard
            ]);
        }
        
        $url = API_URL . 'sendMessage';
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        $result = curl_exec($ch);
        curl_close($ch);
        
        return $result !== false;
    } catch (Exception $e) {
        logError("Send message failed: " . $e->getMessage());
        return false;
    }
}

// Answer callback query (to remove "loading" state)
function answerCallbackQuery($callback_query_id) {
    try {
        $params = [
            'callback_query_id' => $callback_query_id
        ];
        
        $url = API_URL . 'answerCallbackQuery';
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_exec($ch);
        curl_close($ch);
    } catch (Exception $e) {
        logError("Answer callback failed: " . $e->getMessage());
    }
}

// Main keyboard
function getMainKeyboard() {
    return [
        [['text' => '💰 Earn', 'callback_data' => 'earn'], ['text' => '💳 Balance', 'callback_data' => 'balance']],
        [['text' => '🏆 Leaderboard', 'callback_data' => 'leaderboard'], ['text' => '👥 Referrals', 'callback_data' => 'referrals']],
        [['text' => '🏧 Withdraw', 'callback_data' => 'withdraw'], ['text' => '❓ Help', 'callback_data' => 'help']]
    ];
}

// Process commands and callbacks
function processUpdate($update) {
    $users = loadUsers();
    
    if (isset($update['message'])) {
        $chat_id = $update['message']['chat']['id'];
        $text = trim($update['message']['text'] ?? '');
        
        // Create new user if doesn't exist
        if (!isset($users[$chat_id])) {
            $users[$chat_id] = [
                'balance' => 0,
                'last_earn' => 0,
                'referrals' => 0,
                'ref_code' => substr(md5($chat_id . time()), 0, 8),
                'referred_by' => null
            ];
        }
        
        if (strpos($text, '/start') === 0) {
            $ref = explode(' ', $text)[1] ?? null;
            if ($ref && !$users[$chat_id]['referred_by']) {
                foreach ($users as $id => $user) {
                    if ($user['ref_code'] === $ref && $id != $chat_id) {
                        $users[$chat_id]['referred_by'] = $id;
                        $users[$id]['referrals']++;
                        $users[$id]['balance'] += 50; // Referral bonus
                        sendMessage($id, "🎉 New referral! +50 points bonus!");
                        break;
                    }
                }
            }
            
            $msg = "Welcome to Earning Bot!\nEarn points, invite friends, and withdraw your earnings!\nYour referral code: <b>{$users[$chat_id]['ref_code']}</b>";
            sendMessage($chat_id, $msg, getMainKeyboard());
        }
        
    } elseif (isset($update['callback_query'])) {
        $callback_query = $update['callback_query'];
        $chat_id = $callback_query['message']['chat']['id'];
        $data = $callback_query['data'];
        $callback_id = $callback_query['id'];
        
        // Answer callback query immediately
        answerCallbackQuery($callback_id);
        
        if (!isset($users[$chat_id])) {
            $users[$chat_id] = [
                'balance' => 0,
                'last_earn' => 0,
                'referrals' => 0,
                'ref_code' => substr(md5($chat_id . time()), 0, 8),
                'referred_by' => null
            ];
        }
        
        switch ($data) {
            case 'earn':
                $time_diff = time() - $users[$chat_id]['last_earn'];
                if ($time_diff < 60) {
                    $remaining = 60 - $time_diff;
                    $msg = "⏳ Please wait $remaining seconds before earning again!";
                } else {
                    $earn = 10;
                    $users[$chat_id]['balance'] += $earn;
                    $users[$chat_id]['last_earn'] = time();
                    $msg = "✅ You earned $earn points!\nNew balance: {$users[$chat_id]['balance']}";
                }
                break;
                
            case 'balance':
                $msg = "💳 Your Balance\nPoints: {$users[$chat_id]['balance']}\nReferrals: {$users[$chat_id]['referrals']}";
                break;
                
            case 'leaderboard':
                $sorted = array_column($users, 'balance');
                arsort($sorted);
                $top = array_slice($sorted, 0, 5, true);
                $msg = "🏆 Top Earners\n";
                $i = 1;
                foreach ($top as $id => $bal) {
                    $msg .= "$i. User $id: $bal points\n";
                    $i++;
                }
                break;
                
            case 'referrals':
                $msg = "👥 Referral System\nYour code: <b>{$users[$chat_id]['ref_code']}</b>\nReferrals: {$users[$chat_id]['referrals']}\nInvite link: https://t.me/" . explode(':', BOT_TOKEN)[0] . "?start={$users[$chat_id]['ref_code']}\n50 points per referral!";
                break;
                
            case 'withdraw':
                $min = 100;
                if ($users[$chat_id]['balance'] < $min) {
                    $msg = "🏧 Withdrawal\nMinimum: $min points\nYour balance: {$users[$chat_id]['balance']}\nNeed " . ($min - $users[$chat_id]['balance']) . " more points!";
                } else {
                    $amount = $users[$chat_id]['balance'];
                    $users[$chat_id]['balance'] = 0;
                    $msg = "🏧 Withdrawal of $amount points requested!\nOur team will process it soon.";
                    // Add actual withdrawal processing here
                }
                break;
                
            case 'help':
                $msg = "❓ Help\n💰 Earn: Get 10 points/min\n👥 Refer: 50 points/ref\n🏧 Withdraw: Min 100 points\nUse buttons below to navigate!";
                break;
        }
        
        // Edit the original message with new content
        try {
            $params = [
                'chat_id' => $chat_id,
                'message_id' => $callback_query['message']['message_id'],
                'text' => $msg,
                'parse_mode' => 'HTML',
                'reply_markup' => json_encode(['inline_keyboard' => getMainKeyboard()])
            ];
            
            $url = API_URL . 'editMessageText';
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_exec($ch);
            curl_close($ch);
        } catch (Exception $e) {
            // If edit fails, send new message
            sendMessage($chat_id, $msg, getMainKeyboard());
        }
    }
    
    saveUsers($users);
}

// Handle webhook request
function handleWebhook() {
    // Get the input data
    $input = file_get_contents('php://input');
    
    if (empty($input)) {
        // If no input, check if it's a setup request
        if (isset($_GET['setup'])) {
            if (setWebhook()) {
                echo "Webhook set successfully!";
            } else {
                echo "Failed to set webhook";
            }
        } elseif (isset($_GET['delete'])) {
            if (deleteWebhook()) {
                echo "Webhook deleted successfully!";
            } else {
                echo "Failed to delete webhook";
            }
        } else {
            echo "Bot is running!";
        }
        return;
    }
    
    // Decode the update
    $update = json_decode($input, true);
    
    if ($update) {
        // Process the update
        processUpdate($update);
        
        // Send OK response to Telegram
        http_response_code(200);
        echo 'OK';
    } else {
        http_response_code(400);
        echo 'Invalid update';
    }
}

// Start the webhook handler
try {
    handleWebhook();
} catch (Exception $e) {
    logError("Fatal error: " . $e->getMessage());
    http_response_code(500);
    echo 'Internal server error';
}
?>
