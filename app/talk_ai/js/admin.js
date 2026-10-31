// SPDX-License-Identifier: AGPL-3.0-or-later
// The administration page of AI in Talk: settings, status, queue, answers, statistics, house styles.
(function () {
	'use strict'
	const APP = 'talk_ai'
	const t = (text, vars) => (window.t ? window.t(APP, text, vars) : text)
	const url = (path) => OC.generateUrl('/apps/' + APP + path)
	const root = document.getElementById('talk-ai-admin')
	if (!root) {
		return
	}
	const state = OCP.InitialState.loadState(APP, 'settings')
	const form = document.getElementById('talk-ai-form')
	const list = (text) => text.split(',').map((s) => s.trim()).filter(Boolean)
	const el = (tag, props = {}, ...children) => {
		const node = Object.assign(document.createElement(tag), props)
		children.forEach((c) => node.append(c instanceof Node ? c : document.createTextNode(String(c ?? ''))))
		return node
	}

	function fill(settings) {
		Object.entries(settings.values).forEach(([key, value]) => {
			const input = form.elements[key]
			if (!input) {
				return
			}
			if (input.type === 'checkbox') {
				input.checked = value === 'yes'
			} else {
				input.value = value
			}
		})
		root.querySelectorAll('[data-feature]').forEach((input) => {
			input.value = (settings.features[input.dataset.feature] || []).join(', ')
		})
		document.getElementById('talk-ai-rooms-off').value = settings.rooms_off.join(', ')
		document.getElementById('talk-ai-users-off').value = settings.users_off.join(', ')
		document.getElementById('talk-ai-ai-groups').value = (settings.ai_groups || []).join(', ')
		const styles = document.getElementById('talk-ai-styles')
		styles.replaceChildren(...settings.styles.map(styleBox))
	}

	function styleBox(style = {}) {
		const box = el('fieldset', { className: 'talk-ai-style' })
		const field = (name, label, value, placeholder = '') => {
			const id = 'talk-ai-style-' + Math.random().toString(36).slice(2)
			const input = el('input', { id, type: 'text', value: value || '', placeholder })
			input.dataset.name = name
			return el('div', { className: 'talk-ai-field' }, el('label', { htmlFor: id }, label), input)
		}
		box.append(
			field('name', t('Name'), style.name, t('e.g. Club style')),
			field('groups', t('Groups (empty: everyone)'), (style.groups || []).join(', ')),
			field('description', t('Description for the model'), style.description, t('e.g. sober, lots of white space, logo top right')),
			field('palette', t('Colours (#rrggbb, first is the main colour)'), (style.palette || []).join(', ')),
			field('heading', t('Font for headings'), style.fonts && style.fonts.heading),
			field('body', t('Font for text'), style.fonts && style.fonts.body),
			field('templates', t('Templates (format=file, e.g. docx=Letter.dotx)'), Object.entries(style.templates || {}).map(([k, v]) => k + '=' + v).join(', ')),
			field('logo', t('Logo (file name)'), style.logo),
		)
		const fileId = 'talk-ai-upload-' + Math.random().toString(36).slice(2)
		const file = el('input', { type: 'file', id: fileId, accept: '.docx,.dotx,.odt,.ott,.xlsx,.xltx,.ods,.ots,.pptx,.potx,.odp,.otp,.png,.jpg,.jpeg,.svg' })
		const result = el('span', { role: 'status' })
		file.addEventListener('change', async () => {
			const name = box.querySelector('[data-name="name"]').value.trim()
			if (!name || !file.files.length) {
				result.textContent = t('Give the style a name first.')
				return
			}
			const data = new FormData()
			data.append('file', file.files[0])
			const response = await fetch(url('/settings/styles/' + encodeURIComponent(name) + '/files'), { method: 'POST', body: data, headers: { requesttoken: OC.requestToken } })
			const json = await response.json()
			result.textContent = response.ok ? t('Uploaded: {name}. Name it under templates or logo.', { name: json.name }) : json.error
		})
		const remove = el('button', { type: 'button' }, t('Remove this style'))
		remove.addEventListener('click', () => box.remove())
		box.append(el('div', { className: 'talk-ai-field' }, el('label', { htmlFor: fileId }, t('Upload a template or logo')), file, result), remove)
		return box
	}

	function collect() {
		const values = {}
		Array.from(form.elements).forEach((input) => {
			if (input.name) {
				values[input.name] = input.type === 'checkbox' ? (input.checked ? 'yes' : 'no') : input.value
			}
		})
		const features = {}
		root.querySelectorAll('[data-feature]').forEach((input) => { features[input.dataset.feature] = list(input.value) })
		const styles = Array.from(root.querySelectorAll('.talk-ai-style')).map((box) => {
			const v = (name) => box.querySelector('[data-name="' + name + '"]').value
			return {
				name: v('name'), groups: list(v('groups')), description: v('description'), palette: list(v('palette')),
				fonts: { heading: v('heading'), body: v('body') }, logo: v('logo').trim(),
				templates: Object.fromEntries(list(v('templates')).map((pair) => pair.split('=').map((s) => s.trim())).filter((p) => p.length === 2)),
			}
		})
		return {
			values, features, styles, rooms_off: list(document.getElementById('talk-ai-rooms-off').value), users_off: list(document.getElementById('talk-ai-users-off').value),
			ai_groups: list(document.getElementById('talk-ai-ai-groups').value),
		}
	}

	form.addEventListener('submit', async (event) => {
		event.preventDefault()
		root.querySelectorAll('.talk-ai-error').forEach((e) => { e.textContent = '' })
		const response = await fetch(url('/settings'), { method: 'PUT', body: JSON.stringify(collect()), headers: { 'Content-Type': 'application/json', requesttoken: OC.requestToken } })
		const json = await response.json()
		Object.entries(json.errors || {}).forEach(([key, message]) => {
			const node = document.getElementById('talk-ai-' + key + '-help')
			if (node) {
				node.querySelector('.talk-ai-error').textContent = t('Must be {range}.', { range: message })
			}
		})
		document.getElementById('talk-ai-saved').textContent = response.ok ? t('Saved.') : t('Not all settings were saved; see the notes.')
		if (json.settings) {
			fill(json.settings)
		}
	})

	document.getElementById('talk-ai-add-style').addEventListener('click', () => {
		document.getElementById('talk-ai-styles').append(styleBox())
	})

	const rows = (id, items, cells) => {
		document.querySelector('#' + id + ' tbody').replaceChildren(...items.map((item) => el('tr', {}, ...cells(item).map((c) => el('td', {}, c)))))
	}

	async function status(deep = false) {
		const response = await fetch(url('/settings/status') + (deep ? '?deep=1' : ''), { headers: { requesttoken: OC.requestToken } })
		const s = await response.json()
		rows('talk-ai-health', s.health, (h) => [h.name, (h.ok === true ? '✓ ' : h.ok === null ? '– ' : '✗ ') + h.detail, h.ok === true ? '' : h.without])
		rows('talk-ai-jobs', s.jobs, (j) => {
			const cancel = ['queued', 'running'].includes(j.status) ? el('button', { type: 'button' }, t('Cancel')) : ''
			if (cancel) {
				cancel.addEventListener('click', async () => {
					await fetch(url('/settings/jobs/' + j.id + '/cancel'), { method: 'POST', headers: { requesttoken: OC.requestToken } })
					status()
				})
			}
			return [j.id, j.created, j.user, t(j.status), j.name, j.seconds ?? '', j.tools, j.tokens, j.rating === 1 ? '👍' : j.rating === -1 ? '👎' : '', j.outcome, cancel]
		})
		rows('talk-ai-requests', s.requests, (r) => {
			const details = el('details', {}, el('summary', {}, r.tools.length + ' ' + t('tools')),
				el('pre', {}, r.tools.map((x) => x.tool + ' ' + x.args + '\n  → ' + x.result).join('\n') + (r.error ? '\n' + t('Error') + ': ' + r.error : '')))
			return [r.time, r.id, r.user, t(r.status), r.tokens_in + ' / ' + r.tokens_out, r.ms, details]
		})
		document.getElementById('talk-ai-golden').replaceChildren(...Object.entries(s.golden).map(([engine, run]) => el('table', { className: 'talk-ai-table' },
			el('caption', {}, engine + ' — ' + new Date(run.time * 1000).toLocaleString() + ' (' + run.results.filter((r) => r.ok).length + '/' + run.results.length + ')'),
			el('tbody', {}, ...run.results.map((r) => el('tr', {}, ...[r.case, r.ok ? '✓' : '✗', r.status, r.seconds + ' s', r.tools, r.tokens].map((c) => el('td', {}, c))))))))
		const st = s.stats
		const pct = (a, b) => (b > 0 ? Math.round(100 * a / b) + ' %' : '–')
		const table = (title, buckets) => el('table', { className: 'talk-ai-table' },
			el('caption', {}, title),
			el('thead', {}, el('tr', {}, ...['', t('Answers'), t('Failed'), t('Tokens in'), t('Tokens out'), t('Cost (est.)')].map((h) => el('th', { scope: 'col' }, h)))),
			el('tbody', {}, ...Object.entries(buckets).map(([k, b]) => el('tr', {}, ...[k, b.requests, b.failed, b.tokens_in, b.tokens_out, b.cost].map((c) => el('td', {}, c))))))
		document.getElementById('talk-ai-stats').replaceChildren(
			el('p', {}, t('Document jobs: {n} in 7 days, {m} in 30 days; done {done}, average {s} s.', {
				n: st.jobs_7.jobs, m: st.jobs_30.jobs, done: pct(st.jobs_30.done, st.jobs_30.jobs), s: st.jobs_30.seconds,
			})),
			el('p', {}, t('Answers: {n}, failed {f}, tokens {i} in and {o} out (estimated), cost about {c}.', {
				n: st.answers_30.requests, f: st.answers_30.failed, i: st.answers_30.tokens_in, o: st.answers_30.tokens_out, c: st.answers_30.cost,
			})),
			table(t('By person'), st.by_user), table(t('By conversation'), st.by_room), table(t('By day'), st.by_day),
		)
	}

	// The AI connection: engines, their settings, the fallback chain, the scenarios and the use per day.
	const send = async (method, path, body) => {
		const response = await fetch(url(path), {
			method, headers: { 'Content-Type': 'application/json', requesttoken: OC.requestToken }, body: body === undefined ? undefined : JSON.stringify(body),
		})
		let json = {}
		try {
			json = await response.json()
		} catch (e) {
		}
		return { ok: response.ok, json }
	}
	const FIELDS = {
		key: t('API key'), model: t('Model'), baseUrl: t('Base URL'), timeout: t('Seconds to wait for an answer'), maxImages: t('Images per question (empty: the provider\'s limit)'),
		headers: t('Extra headers (JSON, e.g. {"HTTP-Referer": "https://cloud.example"})'), provider: t('Provider name (shown and used for the bot\'s name)'),
		binary: t('Command (name or absolute path)'), home: t('Credentials directory (HOME of the command, where it is logged in)'), php: t('PHP command line (for the tool bridge)'),
		path: t('PATH for the command'), maxBudgetUsd: t('Most dollars per question (empty: no limit)'), taskType: t('Task type (default: chat with tools)'),
	}
	const FLAGS = {
		search: t('Web search'), caching: t('Prompt caching'), thinking: t('Thinking'), documents: t('PDFs to the model directly'), streaming: t('Streaming'),
		fallbacks: t('The provider\'s own fallback model on refusal'), tools: t('Tool calls'), images: t('Images'), structured: t('Structured answers'),
		mcp: t('Tools as real tool calls (MCP bridge; off: as text)'),
	}
	const ai = { engines: [], chosen: '', fallback: [], scenarios: {}, scenarioNames: {} }
	const engineOf = (name) => ai.engines.find((e) => e.name === name)
	const stateText = (status) => (status.ready
		? '✓ ' + t('ready') + (status.model ? ' · ' + status.provider + ' ' + status.model : '')
		: '✗ ' + t('not ready') + ' (' + status.reason + ')')
	const options = (select, items, selected) => select.replaceChildren(...items.map(([value, label]) => el('option', { value, selected: value === selected }, label)))

	function panel(engine) {
		const box = el('fieldset', { className: 'talk-ai-style talk-ai-engine' }, el('legend', {}, el('strong', {}, engine.label)), el('p', { role: 'status' }, stateText(engine.status)))
		const inputs = {}
		engine.fields.forEach((field) => {
			const id = 'talk-ai-engine-' + engine.name + '-' + field
			let input
			if (field === 'key') {
				input = el('input', { id, type: 'password', autocomplete: 'new-password', placeholder: engine.keySet ? t('set — type to replace') : t('not set') })
				const clear = el('button', { type: 'button', disabled: !engine.keySet }, t('Clear the key'))
				clear.addEventListener('click', async () => {
					const r = await send('DELETE', '/settings/ai/engines/' + engine.name + '/key')
					if (r.ok) {
						replace(r.json.engine)
					}
				})
				box.append(el('div', { className: 'talk-ai-field' }, el('label', { htmlFor: id }, FIELDS.key + ': ' + (engine.keySet ? t('set') : t('not set'))), input, clear))
			} else if (field === 'model') {
				const list = el('datalist', { id: id + '-list' })
				input = el('input', { id, type: 'text', value: engine.settings.model || '' })
				input.setAttribute('list', id + '-list')
				const refresh = el('button', { type: 'button' }, t('Models from the provider'))
				refresh.addEventListener('click', async () => {
					refresh.disabled = true
					const r = await send('GET', '/settings/ai/engines/' + engine.name + '/models')
					refresh.disabled = false
					list.replaceChildren(...(r.json.models || []).map((m) => el('option', { value: m })))
					refresh.textContent = t('Models from the provider') + ' (' + (r.json.models || []).length + ')'
				})
				box.append(el('div', { className: 'talk-ai-field' }, el('label', { htmlFor: id }, FIELDS.model), input, list, refresh))
			} else if (field === 'headers') {
				input = el('textarea', { id, rows: 2, value: engine.settings.headers ? JSON.stringify(engine.settings.headers) : '' })
				box.append(el('div', { className: 'talk-ai-field' }, el('label', { htmlFor: id }, FIELDS.headers), input))
			} else {
				const numeric = ['timeout', 'maxImages', 'maxBudgetUsd'].includes(field)
				input = el('input', { id, type: numeric ? 'number' : 'text', value: engine.settings[field] ?? '' })
				if (field === 'maxBudgetUsd') {
					input.step = '0.01'
				}
				box.append(el('div', { className: 'talk-ai-field' }, el('label', { htmlFor: id }, FIELDS[field] || field), input))
			}
			inputs[field] = input
		})
		const flags = {}
		engine.flags.forEach((flag) => {
			const id = 'talk-ai-engine-' + engine.name + '-flag-' + flag
			flags[flag] = el('input', { id, type: 'checkbox', className: 'checkbox', checked: (engine.settings.flags || {})[flag] !== false })
			box.append(el('div', { className: 'talk-ai-field' }, flags[flag], el('label', { htmlFor: id }, FLAGS[flag] || flag)))
		})
		const result = el('span', { role: 'status' })
		const save = el('button', { type: 'button', className: 'primary' }, t('Save the engine\'s settings'))
		save.addEventListener('click', async () => {
			const body = {}
			Object.entries(inputs).forEach(([field, input]) => {
				// An empty key field keeps the key; the key is never sent back.
				if (field !== 'key' || input.value !== '') {
					body[field] = input.value
				}
			})
			if (engine.flags.length) {
				body.flags = Object.fromEntries(Object.entries(flags).map(([f, input]) => [f, input.checked]))
			}
			const r = await send('PUT', '/settings/ai/engines/' + engine.name, body)
			result.textContent = r.ok ? t('Saved.') : Object.entries(r.json.errors || {}).map(([k, v]) => (FIELDS[k] || k) + ': ' + v).join('; ') || t('Not saved.')
			if (r.json.engine) {
				replace(r.json.engine, result.textContent)
			}
		})
		const test = el('button', { type: 'button' }, t('Test connection'))
		test.addEventListener('click', async () => {
			result.textContent = t('Testing …')
			const r = await send('POST', '/settings/ai/engines/' + engine.name + '/test')
			result.textContent = r.json.ok ? '✓ ' + t('answered in {ms} ms: {text}', { ms: r.json.ms, text: r.json.text }) : '✗ ' + (r.json.error || t('no answer'))
		})
		const probe = el('button', { type: 'button' }, t('Test tool calling'))
		probe.addEventListener('click', async () => {
			result.textContent = t('Testing …')
			const r = await send('POST', '/settings/ai/engines/' + engine.name + '/probe')
			result.textContent = r.json.ok ? '✓ ' + t('the model called the tool') : '✗ ' + t('the model did not call the tool') + (r.json.error ? ' (' + r.json.error + ')' : '')
		})
		box.append(el('p', {}, save, ' ', test, ' ', probe, ' ', result))
		return box
	}

	// A saved engine shown anew, with a note that stays.
	function replace(engine, note = '') {
		ai.engines = ai.engines.map((e) => (e.name === engine.name ? engine : e))
		renderConnection()
		const box = document.querySelector('#talk-ai-connection [data-engine="' + engine.name + '"] p:last-child span')
		if (box && note) {
			box.textContent = note
		}
	}

	function renderConnection() {
		const select = document.getElementById('talk-ai-engine')
		options(select, ai.engines.map((e) => [e.name, e.label]), ai.chosen)
		const chosen = engineOf(ai.chosen)
		document.getElementById('talk-ai-engine-state').textContent = chosen ? stateText(chosen.status) : ''
		const shown = chosen ? panel(chosen) : ''
		if (shown) {
			shown.dataset.engine = chosen.name
		}
		document.getElementById('talk-ai-engine-panel').replaceChildren(shown)
		const chain = document.getElementById('talk-ai-fallback')
		chain.replaceChildren(...ai.fallback.map((name, i) => {
			const engine = engineOf(name)
			const move = (by, label) => {
				const button = el('button', { type: 'button', disabled: i + by < 0 || i + by >= ai.fallback.length }, label)
				button.addEventListener('click', () => {
					ai.fallback.splice(i + by, 0, ai.fallback.splice(i, 1)[0])
					renderConnection()
				})
				return button
			}
			const remove = el('button', { type: 'button' }, t('Remove'))
			remove.addEventListener('click', () => {
				ai.fallback.splice(i, 1)
				renderConnection()
			})
			const details = el('details', {}, el('summary', {}, (engine ? engine.label + ' — ' + stateText(engine.status) : name)))
			if (engine) {
				const box = panel(engine)
				box.dataset.engine = engine.name
				details.append(box)
			}
			return el('li', {}, details, move(-1, '↑'), ' ', move(1, '↓'), ' ', remove)
		}))
		options(document.getElementById('talk-ai-fallback-add'), ai.engines.filter((e) => e.name !== ai.chosen && !ai.fallback.includes(e.name)).map((e) => [e.name, e.label]), '')
		renderScenarios()
		renderUsage()
	}

	function renderScenarios() {
		const engines = [['', t('as chosen above')], ...ai.engines.map((e) => [e.name, e.label])]
		document.querySelector('#talk-ai-scenarios tbody').replaceChildren(...Object.entries(ai.scenarioNames).map(([name, label]) => {
			const choice = ai.scenarios[name] || {}
			const engine = el('select', { 'aria-label': label + ': ' + t('Engine') })
			options(engine, engines, choice.engine || '')
			const model = el('input', { type: 'text', value: choice.model || '', 'aria-label': label + ': ' + t('Model'), placeholder: t('the engine\'s') })
			const prompt = el('textarea', { rows: 1, value: choice.prompt || '', 'aria-label': label + ': ' + t('Extra prompt') })
			const row = el('tr', {}, el('td', {}, label, el('br'), el('code', {}, name)), el('td', {}, engine), el('td', {}, model), el('td', {}, prompt))
			row.dataset.scenario = name
			return row
		}))
	}

	function renderUsage() {
		const usage = ai.usage || { days: [], running: 0 }
		document.getElementById('talk-ai-running').textContent = t('Questions running now: {n}', { n: usage.running })
		rows('talk-ai-usage', usage.days, (d) => [d.day, d.engine, d.questions, d.errors, d.tokens_in, d.tokens_out, d.tokens_cached, d.cost ? d.cost.toFixed(2) : ''])
	}

	async function loadConnection() {
		const r = await send('GET', '/settings/ai')
		if (r.ok) {
			Object.assign(ai, r.json)
			renderConnection()
		}
	}

	document.getElementById('talk-ai-engine').addEventListener('change', (event) => {
		ai.chosen = event.target.value
		ai.fallback = ai.fallback.filter((n) => n !== ai.chosen)
		renderConnection()
		document.getElementById('talk-ai-choose-state').textContent = t('Not used yet: press "Use this engine and chain".')
	})
	document.getElementById('talk-ai-fallback-add-button').addEventListener('click', () => {
		const name = document.getElementById('talk-ai-fallback-add').value
		if (name) {
			ai.fallback.push(name)
			renderConnection()
		}
	})
	document.getElementById('talk-ai-choose').addEventListener('click', async () => {
		const r = await send('PUT', '/settings/ai/choice', { engine: ai.chosen, fallback: ai.fallback })
		document.getElementById('talk-ai-choose-state').textContent = r.ok ? t('Saved.') : t('Not saved.')
		if (r.ok) {
			ai.chosen = r.json.chosen
			ai.fallback = r.json.fallback
			renderConnection()
		}
	})
	document.getElementById('talk-ai-scenarios-save').addEventListener('click', async () => {
		const scenarios = {}
		document.querySelectorAll('#talk-ai-scenarios tbody tr').forEach((row) => {
			const [engine, model, prompt] = row.querySelectorAll('select, input, textarea')
			scenarios[row.dataset.scenario] = { engine: engine.value, model: model.value, prompt: prompt.value }
		})
		const r = await send('PUT', '/settings/ai/scenarios', { scenarios })
		document.getElementById('talk-ai-scenarios-state').textContent = r.ok ? t('Saved.') : t('Not saved.')
		if (r.ok) {
			ai.scenarios = r.json.scenarios
		}
	})

	document.getElementById('talk-ai-check').addEventListener('click', () => status(true))
	fill(state)
	status()
	loadConnection()
})()
