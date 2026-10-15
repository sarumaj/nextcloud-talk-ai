<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
/** @var array $_ */
/** @var \OCP\IL10N $l */
$sections = [
	'general' => [$l->t('General'), $l->t('Who the bot answers and what goes with each question.')],
	'quotas' => [$l->t('Quotas and costs'), $l->t('Limits per person and day; 0 means no limit.')],
	'documents' => [$l->t('Document sandbox'), $l->t('Limits of the documents built with code. The runner (sandbox/runner.py) caps them with its own limits.')],
	'retention' => [$l->t('Retention'), $l->t('How long what the app keeps is kept. People can delete their own data at once ("delete my data").')],
	'debug' => [$l->t('Debugging'), $l->t('Off by default: no questions or answers are logged.')],
];
$docs = 'https://git.ciepiela.org/dawid/nextcloud-talk-ai/src/branch/main/docs/admin.md';
?>
<div id="talk-ai-admin" class="section">
	<h2><?php p($l->t('AI in Talk')); ?></h2>
	<p class="settings-hint"><?php p($l->t('The assistant in Talk answers through AI-Hub.')); ?>
		<a href="<?php p($docs); ?>" target="_blank" rel="noreferrer"><?php p($l->t('Administrator\'s guide')); ?> ↗</a></p>

	<h3><?php p($l->t('Status')); ?></h3>
	<p><button type="button" id="talk-ai-check"><?php p($l->t('Check again (probes tool calling and the sandbox)')); ?></button></p>
	<table class="talk-ai-table" id="talk-ai-health" aria-label="<?php p($l->t('Health check')); ?>">
		<thead><tr><th scope="col"><?php p($l->t('Part')); ?></th><th scope="col"><?php p($l->t('State')); ?></th><th scope="col"><?php p($l->t('Without it')); ?></th></tr></thead>
		<tbody><tr><td colspan="3"><?php p($l->t('Loading …')); ?></td></tr></tbody>
	</table>

	<form id="talk-ai-form" novalidate>
	<?php foreach ($sections as $section => [$title, $hint]) { ?>
		<fieldset>
			<legend><h3><?php p($title); ?></h3></legend>
			<p class="settings-hint"><?php p($hint); ?></p>
			<?php foreach ($_['fields'] as $key => [$fieldSection, $type, $default, $min, $max, $label, $help]) {
				if ($fieldSection !== $section) {
					continue;
				} ?>
				<div class="talk-ai-field">
					<?php if ($type === 'bool') { ?>
						<input type="checkbox" class="checkbox" id="talk-ai-<?php p($key); ?>" name="<?php p($key); ?>" aria-describedby="talk-ai-<?php p($key); ?>-help">
						<label for="talk-ai-<?php p($key); ?>"><?php p($l->t($label)); ?></label>
					<?php } else { ?>
						<label for="talk-ai-<?php p($key); ?>"><?php p($l->t($label)); ?></label>
						<?php if ($type === 'text') { ?>
							<textarea id="talk-ai-<?php p($key); ?>" name="<?php p($key); ?>" rows="3" aria-describedby="talk-ai-<?php p($key); ?>-help"></textarea>
						<?php } else { ?>
							<input id="talk-ai-<?php p($key); ?>" name="<?php p($key); ?>" type="<?php p(in_array($type, ['int', 'float'], true) ? 'number' : 'text'); ?>"
								<?php if ($min !== null) { ?>min="<?php p($min); ?>"<?php } ?> <?php if ($max !== null) { ?>max="<?php p($max); ?>"<?php } ?>
								<?php if ($type === 'float') { ?>step="0.01"<?php } ?> aria-describedby="talk-ai-<?php p($key); ?>-help">
						<?php } ?>
					<?php } ?>
					<p class="settings-hint" id="talk-ai-<?php p($key); ?>-help"><?php p($l->t($help)); ?> <span class="talk-ai-error" role="alert"></span></p>
				</div>
			<?php } ?>
		</fieldset>
	<?php } ?>

		<fieldset>
			<legend><h3><?php p($l->t('Features per group')); ?></h3></legend>
			<p class="settings-hint"><?php p($l->t('Group ids, separated by commas. Empty: everyone (youth mode: nobody).')); ?></p>
			<?php foreach ([
				'documents' => $l->t('Writing files (documents, spreadsheets, slides, flash cards)'),
				'sandbox' => $l->t('Document sandbox (documents built with code)'),
				'web' => $l->t('Web search, web pages and citations'),
				'calendar' => $l->t('Calendar and tasks'),
				'youth' => $l->t('Youth mode (no web search, content suitable for minors)'),
			] as $feature => $label) { ?>
				<div class="talk-ai-field">
					<label for="talk-ai-feature-<?php p($feature); ?>"><?php p($label); ?></label>
					<input id="talk-ai-feature-<?php p($feature); ?>" data-feature="<?php p($feature); ?>" type="text" placeholder="<?php p($l->t('everyone')); ?>">
				</div>
			<?php } ?>
		</fieldset>

		<fieldset>
			<legend><h3><?php p($l->t('Emergency stop')); ?></h3></legend>
			<p class="settings-hint"><?php p($l->t('The bot stops at once where it is switched off; running document jobs there are cancelled. Also: occ talk_ai:stop.')); ?></p>
			<div class="talk-ai-field">
				<label for="talk-ai-rooms-off"><?php p($l->t('Conversations switched off (tokens, separated by commas)')); ?></label>
				<input id="talk-ai-rooms-off" type="text">
			</div>
			<div class="talk-ai-field">
				<label for="talk-ai-users-off"><?php p($l->t('People switched off (user ids, separated by commas)')); ?></label>
				<input id="talk-ai-users-off" type="text">
			</div>
		</fieldset>

		<fieldset>
			<legend><h3><?php p($l->t('House styles')); ?></h3></legend>
			<p class="settings-hint"><?php p($l->t('Templates, logo, colours and fonts the bot uses without being asked, per group. Several styles can be chosen by name ("in the club style").')); ?></p>
			<div id="talk-ai-styles"></div>
			<button type="button" id="talk-ai-add-style"><?php p($l->t('Add a house style')); ?></button>
		</fieldset>

		<p><button type="submit" class="primary"><?php p($l->t('Save')); ?></button> <span id="talk-ai-saved" role="status"></span></p>
	</form>

	<h3><?php p($l->t('Document jobs')); ?></h3>
	<table class="talk-ai-table" id="talk-ai-jobs" aria-label="<?php p($l->t('Document jobs')); ?>">
		<thead><tr><th scope="col">#</th><th scope="col"><?php p($l->t('Created')); ?></th><th scope="col"><?php p($l->t('Person')); ?></th><th scope="col"><?php p($l->t('State')); ?></th>
			<th scope="col"><?php p($l->t('Document')); ?></th><th scope="col"><?php p($l->t('Seconds')); ?></th><th scope="col"><?php p($l->t('Tool calls')); ?></th>
			<th scope="col"><?php p($l->t('Tokens (est.)')); ?></th><th scope="col"><?php p($l->t('Rating')); ?></th><th scope="col"><?php p($l->t('Outcome')); ?></th><th scope="col"></th></tr></thead>
		<tbody></tbody>
	</table>

	<h3><?php p($l->t('Statistics (30 days)')); ?></h3>
	<div id="talk-ai-stats"></div>

	<h3><?php p($l->t('Golden set per AI connection')); ?></h3>
	<p class="settings-hint"><?php p($l->t('The acceptance cases with a model, to judge a change of model before it is used: occ talk_ai:golden <test account>.')); ?></p>
	<div id="talk-ai-golden"></div>

	<h3><?php p($l->t('Answers')); ?></h3>
	<p class="settings-hint"><?php p($l->t('Each answer with its request id (also in the log), model, estimated tokens, duration and the tools it ran: why the bot said what it said.')); ?></p>
	<table class="talk-ai-table" id="talk-ai-requests" aria-label="<?php p($l->t('Answers')); ?>">
		<thead><tr><th scope="col"><?php p($l->t('Time')); ?></th><th scope="col"><?php p($l->t('Request')); ?></th><th scope="col"><?php p($l->t('Person')); ?></th>
			<th scope="col"><?php p($l->t('State')); ?></th><th scope="col"><?php p($l->t('Tokens in/out')); ?></th><th scope="col"><?php p($l->t('ms')); ?></th><th scope="col"><?php p($l->t('Tools')); ?></th></tr></thead>
		<tbody></tbody>
	</table>
</div>
