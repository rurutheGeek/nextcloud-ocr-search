import { registerSidebarTab } from '@nextcloud/files'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'

const TAG = 'ocr_search-files-sidebar-tab'
const ICON = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24"><path fill="currentColor" d="M3 3h5v2H5v3H3V3zm13 0h5v5h-2V5h-3V3zM3 16h2v3h3v2H3v-5zm16 0h2v5h-5v-2h3v-3zM7 8h10v2H7V8zm0 3h10v2H7v-2zm0 3h6v2H7v-2z"/></svg>'

const STATUS = {
	pending: () => t('ocr_search', 'Waiting for recognition'),
	failed: () => t('ocr_search', 'Recognition failed'),
	skipped: () => t('ocr_search', 'Not indexed'),
	none: () => t('ocr_search', 'Not indexed'),
	empty: () => t('ocr_search', 'No text found'),
	error: () => t('ocr_search', 'Could not load the text'),
}

async function copy(text) {
	try {
		await navigator.clipboard.writeText(text)
	} catch (error) {
		// Clipboard API is unavailable outside secure contexts.
		const area = document.createElement('textarea')
		area.value = text
		area.style.cssText = 'position:fixed;opacity:0'
		document.body.appendChild(area)
		area.select()
		document.execCommand('copy')
		area.remove()
	}
	window.OCP?.Toast?.success?.(t('ocr_search', 'Copied'))
}

// The Files app sets `node` and `active` as properties on the element.
class OcrSearchTab extends HTMLElement {

	#node = null
	#active = false
	#loaded = null

	set node(value) {
		this.#node = value
		this.#load()
	}

	get node() {
		return this.#node
	}

	set active(value) {
		this.#active = Boolean(value)
		this.#load()
	}

	get active() {
		return this.#active
	}

	connectedCallback() {
		this.#load()
	}

	async #load() {
		const fileId = this.#node?.fileid ?? this.#node?.id
		if (!this.#active || !this.isConnected || !fileId || this.#loaded === fileId) {
			return
		}
		this.#loaded = fileId
		this.replaceChildren()
		let data
		try {
			data = (await axios.get(generateUrl('/apps/ocr_search/api/v1/text/{fileId}', { fileId }))).data
		} catch (error) {
			data = { status: 'error', text: '' }
		}
		if (this.#loaded !== fileId) {
			return
		}
		if (data.status !== 'done' || data.text === '') {
			const note = document.createElement('p')
			note.textContent = STATUS[data.status === 'done' ? 'empty' : data.status]?.() ?? STATUS.none()
			note.style.cssText = 'color:var(--color-text-maxcontrast);padding:8px 0'
			this.replaceChildren(note)
			return
		}
		const button = document.createElement('button')
		button.type = 'button'
		button.className = 'button'
		button.textContent = t('ocr_search', 'Copy text')
		button.addEventListener('click', () => copy(data.text))
		const text = document.createElement('pre')
		text.textContent = data.text
		text.style.cssText = 'white-space:pre-wrap;word-break:break-word;user-select:text;font-family:inherit;margin:12px 0 0'
		this.replaceChildren(button, text)
	}

}

registerSidebarTab({
	id: 'ocr_search',
	displayName: t('ocr_search', 'Text'),
	iconSvgInline: ICON,
	order: 60,
	tagName: TAG,
	enabled: ({ node }) => (node?.mime ?? '').startsWith('image/'),
	async onInit() {
		if (!customElements.get(TAG)) {
			customElements.define(TAG, OcrSearchTab)
		}
	},
})
