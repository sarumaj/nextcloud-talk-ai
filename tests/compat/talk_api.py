#!/usr/bin/env python3
# SPDX-License-Identifier: AGPL-3.0-or-later
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
    "lib/Events/BotInstallEvent.php": [("BotInstallEvent(name, secret, url, description, features)", r"__construct\(\s*(?:#\[[^\]]*\]\s*)*(?:\w+ )*string \$name,\s*(?:#\[[^\]]*\]\s*)*(?:\w+ )*string \$secret,\s*(?:#\[[^\]]*\]\s*)*(?:\w+ )*string \$url,\s*(?:#\[[^\]]*\]\s*)*(?:\w+ )*string \$description = '',\s*(?:#\[[^\]]*\]\s*)*(?:\w+ )*\?int \$features")],
    "lib/Model/BotServerMapper.php": [("BotServerMapper::findByUrl", r"function findByUrl\(string \$url")],
    # Calls the bot answers (Service\Calls, Listener\CallListener; the External App reads the signaling settings).
    "lib/Model/BotConversationMapper.php": [("BotConversationMapper::findForToken", r"function findForToken\(string \$token")],
    "lib/Model/BotConversation.php": [("BotConversation::getBotId", r"@method int getBotId\("), ("BotConversation::getState", r"@method int getState\(")],
    "lib/Events/CallStartedEvent.php": [("CallStartedEvent", r"class CallStartedEvent extends ACallStartedEvent")],
    "lib/Events/CallEndedEvent.php": [("CallEndedEvent", r"class CallEndedEvent extends")],
    "lib/Events/CallEndedForEveryoneEvent.php": [("CallEndedForEveryoneEvent", r"class CallEndedForEveryoneEvent extends")],
    "lib/Events/ARoomModifiedEvent.php": [("ARoomModifiedEvent::getActor", r"function getActor\(\): \?Participant")],
    "lib/Controller/SignalingController.php": [("SignalingController::getSettings (GET signaling/settings)", r"function getSettings\(string \$token")],
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
