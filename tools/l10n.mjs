// Generates l10n/<lang>.js from l10n/<lang>.json. Nextcloud loads the .json
// file for PHP strings and the .js file for strings used by the Files action,
// so both have to ship with the app.
import { readFileSync, readdirSync, writeFileSync } from 'node:fs'

const appId = readFileSync('appinfo/info.xml', 'utf8').match(/<id>([^<]+)<\/id>/)[1]

for (const file of readdirSync('l10n').filter((name) => name.endsWith('.json'))) {
	const { translations, pluralForm } = JSON.parse(readFileSync(`l10n/${file}`, 'utf8'))
	const body = JSON.stringify(translations, null, '\t').replace(/\n/g, '\n\t')
	writeFileSync(
		`l10n/${file.replace(/\.json$/, '.js')}`,
		`OC.L10N.register(\n\t${JSON.stringify(appId)},\n\t${body},\n\t${JSON.stringify(pluralForm)}\n);\n`,
	)
}
