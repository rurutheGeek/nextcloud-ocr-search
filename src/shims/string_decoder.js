// esbuild はブラウザ向けに固めるが、依存パッケージが未使用のNode内蔵機能を
// import している。実行されない経路なので、ブラウザで動く最小実装を渡す。
export class StringDecoder {
	constructor(encoding = 'utf-8') {
		this.decoder = new TextDecoder(encoding)
	}

	write(buffer) {
		return this.decoder.decode(buffer, { stream: true })
	}

	end(buffer) {
		return buffer ? this.decoder.decode(buffer) : this.decoder.decode()
	}
}

export default { StringDecoder }
