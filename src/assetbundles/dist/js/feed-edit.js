(function () {
	// New feeds only: an existing handle names the queue lock and the download filename, so it must
	// not follow the name around.
	if (document.getElementById('handle').value === '') {
		new Craft.HandleGenerator('#name', '#handle');
	}

	const sourceSelect = document.getElementById('source');
	const siteInput = document.getElementById('siteId');
	const container = document.getElementById('source-ids');

	const checkedSourceIds = () =>
		Array.from(container.querySelectorAll('input[type=checkbox]:checked'))
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

		container.innerHTML = response.data.html;
	};

	sourceSelect.addEventListener('change', () => {
		refreshSourceIds().catch((error) => {
			Craft.cp.displayError(error.response.data.message);
		});
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
	const craftBox = document.getElementById('image-craft-transform');
	const sizeBox = document.getElementById('image-size');
	const craftSelect = document.getElementById('imageTransform');

	// Only rendered for someone who may build, so unlike the fields above these can be absent.
	const testBox = document.getElementById('image-test');
	const testButton = document.getElementById('test-image');
	const previewButton = document.getElementById('preview-feed');

	const refreshImageFields = () => {
		const engine = engineSelect.value;
		const usingNamedCraft = engine === 'craft' && craftSelect.value !== '';
		craftBox.classList.toggle('hidden', engine !== 'craft');
		sizeBox.classList.toggle('hidden', engine === 'none' || usingNamedCraft);
		if (testBox !== null) {
			testBox.classList.toggle('hidden', engine === 'none');
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

		testButton.addEventListener('click', () => {
			testButton.classList.add('loading');
			Craft.sendActionRequest('POST', 'product-feeds/feeds/test-image', {
				data: {
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
					const result = response.data;
					if (result.error) {
						testOutput.innerHTML = '<p class="error">' + result.error + '</p>';
						return;
					}

					const dimensions = result.width + ' &times; ' + result.height;
					const params = { width: result.minimumWidth, height: result.minimumHeight };
					const minimum = result.minimumWidth === null
						? ''
						: (result.meetsMinimum
							? '<span class="success">' + Craft.t('product-feeds', 'imageTest.meetsMinimum', params) + '</span>'
							: '<span class="error">' + Craft.t('product-feeds', 'imageTest.belowMinimum', params) + '</span>');
					testOutput.innerHTML =
						'<img src="' + result.url + '" class="pf-test-thumb" alt="">'
						+ '<p class="light">' + dimensions + ' &middot; ' + result.contentType + ' &middot; HTTP ' + result.status + '</p>'
						+ '<p>' + minimum + '</p>'
						+ '<p><a href="' + result.url + '" target="_blank" rel="noopener">' + result.url + '</a></p>';
				})
				.catch((error) => {
					Craft.cp.displayError(error.response.data.message);
				})
				.finally(() => {
					testButton.classList.remove('loading');
				});
		});
	}

	if (previewButton === null) {
		return;
	}

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
			.catch((error) => {
				Craft.cp.displayError(error.response.data.message);
			})
			.finally(() => {
				previewButton.classList.remove('loading');
			});
	});
})();
