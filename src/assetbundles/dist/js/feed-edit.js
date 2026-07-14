(function () {
	// New feeds only: an existing handle names the queue lock and the download filename, so it must
	// not follow the name around.
	if (document.getElementById('handle').value === '') {
		new Craft.HandleGenerator('#name', '#handle');
	}

	// A request that never reached the server (dropped connection, proxy error page) rejects with no
	// response body to read a message out of.
	const showRequestError = (error) => Craft.cp.displayError(error.response?.data?.message ?? error.message);

	const buildButton = document.getElementById('build-now');

	if (buildButton !== null) {
		buildButton.addEventListener('click', () => {
			buildButton.classList.add('loading');

			Craft.sendActionRequest('POST', 'product-feeds/feeds/build', {
				data: {
					feedId: buildButton.dataset.feedId,
				},
			})
				.then((response) => {
					Craft.cp.displayNotice(response.data.message);
				})
				.catch(showRequestError)
				.finally(() => {
					buildButton.classList.remove('loading');
				});
		});
	}

	const sourceSelect = document.getElementById('source');
	const siteInput = document.getElementById('siteId');
	const sourceIdsField = document.getElementById('source-ids');

	const checkedSourceIds = () =>
		Array.from(sourceIdsField.querySelectorAll('input[type=checkbox]:checked'))
			.map((checkbox) => checkbox.value);

	// Which product types or sections are pickable depends on both the source and the site, and on
	// whether each one has a public URL there. The server owns that answer and renders the field.
	const refreshSourceIds = async () => {
		const response = await Craft.sendActionRequest('POST', 'product-feeds/feeds/source-options', {
			data: {
				source: sourceSelect.value,
				siteId: siteInput.value,
				sourceIds: checkedSourceIds(),
			},
		});

		sourceIdsField.innerHTML = response.data.html;
	};

	sourceSelect.addEventListener('change', () => {
		refreshSourceIds().catch(showRequestError);
	});

	// Open the chip in a slideout rather than navigating, so unsaved feed changes survive.
	document.addEventListener('click', (event) => {
		const chip = event.target.closest('.pf-excluded-item .chip');
		if (chip === null) {
			return;
		}

		event.preventDefault();
		Craft.createElementEditor(chip.dataset.type, chip);
	});

	// Image engine controls which fields apply: a named Craft transform carries its own size, so the
	// size inputs only matter for a custom size or a CDN engine.
	const engineSelect = document.getElementById('imageEngine');
	const craftTransformField = document.getElementById('image-craft-transform');
	const imageSizeField = document.getElementById('image-size');
	const craftSelect = document.getElementById('imageTransform');

	// Only rendered for someone who may build, so unlike the fields above these can be absent.
	const imageTestField = document.getElementById('image-test');
	const testButton = document.getElementById('test-image');
	const previewButton = document.getElementById('preview-feed');

	const refreshImageFields = () => {
		const engine = engineSelect.value;
		const usingNamedCraft = engine === 'craft' && craftSelect.value !== '';
		craftTransformField.classList.toggle('hidden', engine !== 'craft');
		imageSizeField.classList.toggle('hidden', engine === 'none' || usingNamedCraft);
		if (imageTestField !== null) {
			imageTestField.classList.toggle('hidden', engine === 'none');
		}
	};

	engineSelect.addEventListener('change', refreshImageFields);
	craftSelect.addEventListener('change', refreshImageFields);
	refreshImageFields();

	if (testButton !== null) {
		const testOutput = document.getElementById('image-test-output');
		const imageAttribute = testButton.dataset.imageAttribute;

		// The test resolves the image the same way a build does, so it has to send the mapping row the
		// admin is looking at rather than the one the feed was last saved with.
		const imageMapping = () => {
			const source = document.querySelector('[name="fieldMapping[' + imageAttribute + '][source]"]');
			const defaultAsset = document.querySelector('[name="fieldMapping[' + imageAttribute + '][default][]"]');

			return {
				[imageAttribute]: {
					source: source.value,
					default: defaultAsset === null ? '' : defaultAsset.value,
				},
			};
		};

		const renderTest = (result) => {
			testOutput.replaceChildren();

			// A URL that answers with a 404 page or an HTML error still has a body, so only `ok` says an
			// image came back. Without it the thumbnail below would render broken.
			if (!result.ok) {
				const errorParagraph = document.createElement('p');
				errorParagraph.className = 'error';
				errorParagraph.textContent = result.error ?? Craft.t('product-feeds', 'imageTest.notAnImage', {
					status: result.status,
					contentType: result.contentType ?? '?',
				});
				testOutput.append(errorParagraph);
				return;
			}

			const thumbnail = document.createElement('img');
			thumbnail.className = 'pf-test-thumb';
			thumbnail.src = result.url;
			thumbnail.alt = '';

			const dimensionsParagraph = document.createElement('p');
			dimensionsParagraph.className = 'light';
			dimensionsParagraph.textContent = result.width + ' × ' + result.height
				+ ' · ' + result.contentType
				+ ' · HTTP ' + result.status;

			const link = document.createElement('a');
			link.href = result.url;
			link.target = '_blank';
			link.rel = 'noopener';
			link.textContent = result.url;

			const linkParagraph = document.createElement('p');
			linkParagraph.append(link);

			testOutput.append(thumbnail, dimensionsParagraph);

			// The platform may publish no minimum, in which case there is no verdict to give.
			if (result.minimumWidth !== null) {
				const verdict = document.createElement('p');
				const params = { width: result.minimumWidth, height: result.minimumHeight };
				verdict.className = result.meetsMinimum ? 'success' : 'error';
				verdict.textContent = result.meetsMinimum
					? Craft.t('product-feeds', 'imageTest.meetsMinimum', params)
					: Craft.t('product-feeds', 'imageTest.belowMinimum', params);
				testOutput.append(verdict);
			}

			testOutput.append(linkParagraph);
		};

		testButton.addEventListener('click', () => {
			testButton.classList.add('loading');
			Craft.sendActionRequest('POST', 'product-feeds/feeds/test-image', {
				data: {
					// The filter is not in this payload: the server reads it off the saved feed, so the test
					// runs against a product the feed actually publishes.
					feedId: testButton.dataset.feedId,
					siteId: siteInput.value,
					platform: document.getElementById('platform').value,
					source: sourceSelect.value,
					sourceIds: checkedSourceIds(),
					fieldMapping: imageMapping(),
					imageEngine: engineSelect.value,
					imageTransform: craftSelect.value,
					imageWidth: document.getElementById('imageWidth').value,
					imageHeight: document.getElementById('imageHeight').value,
					imageFit: document.getElementById('imageFit').value,
				},
			})
				.then((response) => {
					renderTest(response.data);
				})
				.catch(showRequestError)
				.finally(() => {
					testButton.classList.remove('loading');
				});
		});
	}

	if (previewButton !== null) {
		const previewOutput = document.getElementById('preview-output');

		const preview = async () => {
			previewButton.classList.add('loading');

			const response = await Craft.sendActionRequest('POST', 'product-feeds/feeds/preview', {
				data: {
					feedId: previewButton.dataset.feedId,
				},
			});

			previewOutput.innerHTML = response.data.html;
		};

		previewButton.addEventListener('click', () => {
			preview()
				.catch(showRequestError)
				.finally(() => {
					previewButton.classList.remove('loading');
				});
		});
	}
})();
