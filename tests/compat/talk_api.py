#!/usr/bin/env python3
"""Checks that the Talk (spreed) versions the app supports still have the classes,
methods and parameters the app uses: run in CI before a release (B-07), so a break
in Talk's bot or chat API is found there, not on a server.

talk_api.py [branch …]   default: the stable branches of the supported Nextcloud versions and main.
"""
import re, sys, urllib.request

BRANCHES = sys.argv[1:] or ["stable32", "stable33", "stable34", "stable35", "main"]
# file -> list of (what, regex)
USES = {
    "lib/Events/BotInvokeEvent.php": [("BotInvokeEvent::getBotUrl", r"function getBotUrl\("), ("BotInvokeEvent::getMessage", r"function getMessage\("),
        ("BotInvokeEvent::addReaction", r"function addReaction\(string \$emoji"), ("BotInvokeEvent::addAnswer", r"function addAnswer\(string \$message, bool\|int \$reply")],
    "lib/Events/BotEnabledEvent.php": [("BotEnabledEvent::getBotServer", r"function getBotServer\(")],
    "lib/Events/BotInstallEvent.php": [("BotInstallEvent(name, secret, url, description, features)", r"__construct\(\s*(?:\w+ )*string \$name,\s*(?:\w+ )*string \$secret,\s*(?:\w+ )*string \$url,\s*(?:\w+ )*string \$description = '',\s*(?:\w+ )*\?int \$features")],
    "lib/Model/BotServerMapper.php": [("BotServerMapper::findByUrl", r"function findByUrl\(string \$url")],
    "lib/Model/BotServer.php": [("BotServer::setFeatures", r"(?:@method void setFeatures\(int|function setFeatures\()")],
    "lib/Model/Bot.php": [("Bot::FEATURE_EVENT", r"FEATURE_EVENT = 4"), ("Bot::FEATURE_REACTION", r"FEATURE_REACTION = 8")],
    "lib/Chat/ChatManager.php": [("ChatManager::sendMessage", r"function sendMessage\(\s*Room \$chat,\s*\?Participant \$participant,\s*string \$actorType,\s*string \$actorId,\s*string \$message,\s*\\DateTime \$creationDateTime,\s*\?IComment \$replyTo"),
        ("ChatManager::editMessage", r"function editMessage\(Room \$chat, IComment \$comment, Participant \$participant, \\DateTime \$editTime, string \$message"),
        ("ChatManager::addSystemMessage", r"function addSystemMessage\("), ("ChatManager::getHistory", r"function getHistory\(Room \$chat, int \$offset, int \$limit, bool \$includeLastKnown"),
        ("ChatManager::getComment", r"function getComment\(Room \$chat, string \$\w+\)")],
    "lib/Chat/MessageParser.php": [("MessageParser::createMessage", r"function createMessage\(Room \$room, \?Participant \$participant, IComment \$comment, IL10N \$l"), ("MessageParser::parseMessage", r"function parseMessage\(")],
    "lib/Service/ParticipantService.php": [("ParticipantService::getParticipant", r"function getParticipant\(Room \$room, \?string \$userId")],
    "lib/Service/PollService.php": [("PollService::createPoll", r"function createPoll\(int \$roomId, string \$actorType, string \$actorId, string \$displayName, string \$question, array \$options, int \$resultMode, int \$maxVotes")],
    "lib/Participant.php": [("Participant::hasModeratorPermissions", r"function hasModeratorPermissions\(")],
    # Conversation subfolders came with Talk for Nextcloud 34; before, files go to the attachment folder (Conversation::sharedFolder).
    "lib/Config.php": [("Config::getAttachmentFolder", r"function getAttachmentFolder\(string \$userId")],
    "lib/Events/AReactionEvent.php": [("AReactionEvent::getReaction", r"function getReaction\(")],
}

failed = 0
for branch in BRANCHES:
    for path, checks in USES.items():
        url = "https://raw.githubusercontent.com/nextcloud/spreed/%s/%s" % (branch, path)
        try:
            source = urllib.request.urlopen(url, timeout=30).read().decode()
        except Exception as e:
            if branch == "main" or "404" not in str(e):
                print("%s: %s: cannot fetch (%s)" % (branch, path, e))
                failed += branch != "main"
            continue
        for what, pattern in checks:
            if not re.search(pattern, source):
                print("%s: %s changed or is gone" % (branch, what))
                failed += 1
    print("%s: checked" % branch)
sys.exit(1 if failed else 0)
