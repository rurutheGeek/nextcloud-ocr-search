// path.join のブラウザ向け最小実装（FilePicker がWebDAVのパス組み立てに使う）。
export function join(...parts) {
	return parts
		.filter((part) => part !== undefined && part !== null && part !== '')
		.map((part, index) => {
			const text = String(part)
			return index === 0 ? text.replace(/\/+$/, '') : text.replace(/^\/+|\/+$/g, '')
		})
		.join('/') || '/'
}

export default { join }
