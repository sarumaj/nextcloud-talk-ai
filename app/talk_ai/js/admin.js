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
		return { values, features, styles, rooms_off: list(document.getElementById('talk-ai-rooms-off').value), users_off: list(document.getElementById('talk-ai-users-off').value) }
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

	document.getElementById('talk-ai-check').addEventListener('click', () => status(true))
	fill(state)
	status()
})()
