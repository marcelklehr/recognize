const Flickr = require('flickr-sdk')
const { GOOGLE_IMG_SCRAP , GOOGLE_QUERY } = require('google-img-scrap');
const download = require('download')
const uniq = require('lodash/uniq')
const flatten = require('lodash/flatten')
const execa = require('execa')
const glob = require('fast-glob')
const Parallel = require('async-parallel')
const LABELS = {
	landmarks_africa: require('../src/landmarks/africa.json').name,
	landmarks_asia: require('../src/landmarks/asia.json').name,
	landmarks_europe: require('../src/landmarks/europe.json').name,
	landmarks_north_america: require('../src/landmarks/north_america.json').name,
	landmarks_south_america: require('../src/landmarks/south_america.json').name,
	landmarks_oceania: require('../src/landmarks/oceania.json').name,
}

const PHOTOS_PER_LABEL = 1
const PHOTOS_OLDER_THAN = 1627464319 // 2021-07-28; for determinism
const flickr = new Flickr(process.env.FLICKR_API_KEY)

;(async function() {
	const modelName = process.argv[2]
	const labels = uniq(Object.values(LABELS[modelName])).slice(0, 10000) // GitHub has a 6h timeout, sadly


	await Parallel.each(labels, async label => {
		try {
			let urls = await findPhotos(label, PHOTOS_PER_LABEL)
			await Promise.all(
				flatten(urls).map(url => download(url, 'temp_images/' + label))
			)
		} catch (e) {
			console.log('Error downloading photos for label "' + label + '"')
			console.log(e)
		}
	}, 20)

	const results = await Parallel.map(labels, async label => {
		// Calculate the true positive rate
		let tpr = 0

		try {
			const files = await glob(['temp_images/' + label + '/*'])
			if (!files.length) {
				tpr = -1
				throw new Error('No photos found for label "' + label + '"')
			}
			const { stdout } = await execa('node', [__dirname + '/../src/classifier_landmarks.js'].concat(files))
			const predictions = stdout.split('\n')
				.map(line => JSON.parse(line))
			const matches = predictions
				.map((labels, i) => labels.includes(label) ? files[i] : labels)

			const matchCount = matches.filter((item, i) => files[i] === item).length
			tpr = matchCount / files.length

			console.log('Processed photos for label "' + label + '"')
		} catch (e) {
			tpr = -1
			console.log('Error processing photos for label "' + label + '"')
			console.log(e)
		}

		console.log({ tpr })

		return { tpr }
	}, 4)

	const sum = results.reduce((acc, val) => {
		return { tpr: acc.tpr + (val.tpr !== -1 ? val.tpr : 0), count: acc.count + (val.tpr !== -1 ? 1 : 0)  }
	}, { tpr: 0, count: 0 })

	const averageTPR = sum.tpr / results.length

	console.log({ averageTPR })

	if (averageTPR < 0.01) {
		process.exit(1)
	}
})()

function findPhotos(label, amount = PHOTOS_PER_LABEL) {
	console.log('FLICKR search: '+label)
	return flickr.photos.search({
		text: label,
		per_page: amount,
		media: 'photos',
		content_type: 1,
		max_upload_date: PHOTOS_OLDER_THAN,
		sort: 'relevance',
	}).then(function(res) {
		if (res.body.stat === 'ok') {
			return res.body.photos.photo.map(photo => `https://live.staticflickr.com/${photo.server}/${photo.id}_${photo.secret}.jpg`)
		} else {
			console.log(res.body)
		}

	}).catch(function(err) {
		throw err
	})
}

async function findPhotosGoogle(label, amount= PHOTOS_PER_LABEL) {
	console.log('GOOGLE search: '+label)
	const results = await GOOGLE_IMG_SCRAP({
		search: label,
		query: {
			EXTENSION: GOOGLE_QUERY.EXTENSION.JPG
		},
		limit: amount,
	});

	return results.result.map(i => i.url)
}
