<?php
/**
 * Backups all your Trello boards (including cards, checklists, comments, etc.) as one JSON file per board.
 *
 * See: https://github.com/mattab/trello-backup
 *
 * License: GPL v3 or later (I'm using that Wordpress function below and WP is released under GPL)
 */

if ($argc == 2) {
    $config_file = $argv[1];
} else {
    $config_file = __DIR__ . DIRECTORY_SEPARATOR . 'config.php';
}

// check config file exits
if (!file_exists($config_file)) {
    die("Please duplicate the config.example.php file to config.php and fill in your details (as follows).");
}
require_once $config_file;

if(empty($timezone))
{
	$timezone = "UTC";
	print "No timezone set in config ($config_file), using $timezone\n";
}
date_default_timezone_set($timezone);

// If the application_token looks incorrect we display help
if (strlen($application_token) < 30) {
    // 0) Fetch the Application tokenn
    // Source: https://trello.com/docs/gettingstarted/index.html#getting-a-token-from-a-user
    // We get the app token with "read" only access forever
    $url_token = "https://trello.com/1/authorize?key=" . $key . "&name=My+Trello+Backup&expiration=never&response_type=token";
    die("Go to this URL with your web browser (eg. Firefox) to authorize your Trello Backups to run:\n$url_token\n");
}

// Prepare proxy configuration if necessary
$ctx = null;
$context = array();
if (!empty($proxy)) {
    $context['http'] = array(
        'proxy' => 'tcp://' . $proxy,
        'request_fullurl' => true
    );
}

// Force protocol to HTTP 1.1 to avoid error
$context['http']['protocol_version'] = '1.1';
$ctx = stream_context_create($context);

// 1) Fetch all Trello Boards
$application_token = trim($application_token);
$url_boards = "https://api.trello.com/1/members/me/boards?&key=$key&token=$application_token";
$response = file_get_contents($url_boards, false, $ctx);
if ($response === false) {
    die("Error requesting boards - maybe try again later and/or check your internet connection\n");
}
$boardsInfo = json_decode($response);
if (empty($boardsInfo)) {
    die("Error requesting your boards - maybe check your tokens are correct.\n");
}

// 2) Fetch all Trello Organizations
$url_organizations = "https://api.trello.com/1/members/me/organizations?&key=$key&token=$application_token";
$response = file_get_contents($url_organizations, false, $ctx);
$organizationsInfo = json_decode($response);
$organizations = array();

foreach ($organizationsInfo as $org) {
    $organizations[$org->id] = $org->displayName;
}

// 3) Fetch all Trello Boards from the organizations that the user has read access to
if ($backup_all_organization_boards) {
    foreach ($organizations as $organization_id => $organization_name) {
        $url_boards = "https://api.trello.com/1/organizations/$organization_id/boards?&key=$key&token=$application_token";
        $response = file_get_contents($url_boards, false, $ctx);
        $organizationBoardsInfo = json_decode($response);
        if (empty($organizationBoardsInfo)) {
            die("Error requesting the organization $organization_name boards - maybe check your tokens are correct.\n");
        } else {
            $boardsInfo = array_merge($organizationBoardsInfo, $boardsInfo);
        }
    }
}

// 4) Only backup the "open" boards
$boards = array();
foreach ($boardsInfo as $board) {
    if (!$backup_closed_boards && $board->closed) {
        continue;
    }

    if (isset($ignore_boards) && in_array($board->name, $ignore_boards)) {
        continue;
    }
    if (isset($boards_to_download) && !empty($boards_to_download) && !in_array($board->name, $boards_to_download)) {
        continue;
    }

    $boards[$board->id] = (object)array(
        "name" => $board->name,
        "orgName" => (isset($organizations[$board->idOrganization]) ? $organizations[$board->idOrganization] : ''),
        "closed" => (($board->closed) ? true : false)
    );
}
if (empty($boards)) {
    die("Error: No boards found in your account. Please review your configuration or start by adding a board to your account.");
}

echo count($boards) . " boards to backup... \n";

// 5) Backup now!
foreach ($boards as $id => $board) {
    $url_individual_board_json = "https://api.trello.com/1/boards/$id?actions=all&actions_limit=1000&card_attachment_fields=all&cards=all&lists=all&members=all&member_fields=all&card_attachment_fields=all&checklists=all&fields=all&key=$key&token=$application_token";
    $dirname = getPathToStoreBackups($path, $board, $filename_append_datetime);
    
    if(!file_exists($path)) {
        create_backup_dir($path);
    }

    if(!is_writable($path)) {
        die("You don't have permission to write to backup dir $path");
    }
    
    $filename = $dirname . '.json';
    if (!file_exists($filename)) {
        echo "recording " . (($board->closed) ? 'the closed ' : '') . "board '" . $board->name . "' " . (empty($board->orgName) ? "" : "(within organization '" . $board->orgName . "')") . " in filename $filename ...\n";
        $response = file_get_contents($url_individual_board_json, false, $ctx);
        $decoded = json_decode($response);
        if (empty($decoded)) {
            die("The board '$board->name' or organization '$board->orgName' could not be downloaded, response was : $response ");
        }
        if(file_put_contents($filename, $response) === false) {
            die("An error occured while writing to $filename");
        }

        // 5a) Backup the attachments
        if($backup_attachments) {
            $trelloObject = json_decode($response);
            $attachments = array();
            foreach ($trelloObject->actions as $member) {
                if (isset($member->data->attachment->url)) {
                    $attachments[$member->data->attachment->url] = $member->data->attachment->id . '-' . $member->data->attachment->name;
                }
            }

            if(!empty($attachments)) {
                echo "\t" . count($attachments) . " attachments will now be downloaded and backed up...\n";

                if (!file_exists($dirname)) {
                    mkdir($dirname, 0777, true);
                }
                $i = 1;
                foreach ($attachments as $url => $name) {
                    $pathForAttachment = $dirname . '/' . sanitize_file_name($name);
                    file_put_contents($pathForAttachment, file_get_contents($url));
                    echo "\t" . $i++ . ") " . $name . " in " . $pathForAttachment . "\n";
                }
            }
        }   
    }

}
echo "your Trello boards are now safely downloaded!\n";

/**
 * @param $path
 * @param $board
 * @param $filename_append_datetime
 * @return string
 */
function getPathToStoreBackups($path, $board, $filename_append_datetime)
{
    return "$path/trello"
    . (($board->closed) ? '-CLOSED' : '')
    . (!empty($board->orgName) ? '-org-' . sanitize_file_name($board->orgName) : '')
    . '-board-' . sanitize_file_name($board->name)
    . (($filename_append_datetime) ? '-' . date($filename_append_datetime, time()) : '');
}

/**
 * Found in Wordpress:
 *
 * Sanitizes a filename replacing whitespace with dashes
 *
 * Removes special characters that are illegal in filenames on certain
 * operating systems and special characters requiring special escaping
 * to manipulate at the command line. Replaces spaces and consecutive
 * dashes with a single dash. Trim period, dash and underscore from beginning
 * and end of filename.
 *
 * @param string $filename The filename to be sanitized
 * @return string The sanitized filename
 */
function sanitize_file_name($filename)
{
    $special_chars = array("?", "[", "]", "/", "\\", "=", "<", ">", ":", ";", ",", "'", "\"", "&", "$", "#", "*", "(", ")", "|", "~", "`", "!", "{", "}");
    $filename = str_replace($special_chars, '', $filename);
    $filename = preg_replace('/[\s-]+/', '-', $filename);
    $filename = trim($filename, '.-_');
    return $filename;
}

function create_backup_dir($dirname)
{
	if(!mkdir($dirname, 0777, $recursive = true))
	{
		die("Error creating backup dir - directory $dirname is not writeable\n");
	}
}
