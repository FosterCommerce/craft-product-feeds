(function () {
	const { tableData, canEdit, canBuild } = window.productFeedsIndex;

	// The admin table re-renders its rows, so the listener lives on a stable parent.
	document.addEventListener('click', (event) => {
		const button = event.target.closest('.pf-build-feed');

		if (button === null) {
			return;
		}

		button.classList.add('loading');

		Craft.sendActionRequest('POST', 'product-feeds/feeds/build', {
			data: {
				feedId: button.dataset.feedId,
			},
		})
			.then((response) => {
				Craft.cp.displayNotice(response.data.message);
			})
			.catch((error) => {
				// A request that never reached the server (dropped connection, proxy error page) rejects with
				// no response body to read a message out of.
				Craft.cp.displayError(error.response?.data?.message ?? error.message);
			})
			.finally(() => {
				button.classList.remove('loading');
			});
	});

	// With no feeds the template renders an empty state instead of the table's container.
	if (tableData.length === 0) {
		return;
	}

	const columns = [
		{ name: '__slot:title', title: Craft.t('product-feeds', 'index.nameColumn') },
		{ name: 'feedStatus', title: Craft.t('product-feeds', 'index.statusColumn') },
		{ name: 'platform', title: Craft.t('product-feeds', 'index.platformColumn') },
		{ name: 'source', title: Craft.t('product-feeds', 'index.sourceColumn') },
		{
			name: 'lastBuilt',
			title: Craft.t('product-feeds', 'index.lastBuiltColumn'),
			callback: (value) => {
				if (value.error) {
					return '<span class="error">' + value.error + '</span>';
				}

				return value.at ? value.label + ' <span class="light">' + value.at + '</span>' : value.label;
			},
		},
		{ name: 'items', title: Craft.t('product-feeds', 'index.itemsColumn') },
		{
			name: 'issues',
			title: Craft.t('product-feeds', 'index.issuesColumn'),
			callback: (value) => {
				return value
					? '<span class="status pending"></span> '
						+ Craft.t('product-feeds', 'index.excludedCount', { n: value })
					: '';
			},
		},
		{ name: 'size', title: Craft.t('product-feeds', 'index.sizeColumn') },
		{
			name: 'feedUrl',
			title: Craft.t('product-feeds', 'index.urlColumn'),
			callback: (value) => {
				return value
					? '<a href="' + value + '" target="_blank" rel="noopener">'
						+ Craft.t('product-feeds', 'index.openFeed') + '</a>'
					: '';
			},
		},
	];

	if (canBuild) {
		columns.push({
			name: 'build',
			title: '',
			callback: (feedId) => {
				return '<button type="button" class="btn small pf-build-feed" data-feed-id="' + feedId + '">'
					+ Craft.t('product-feeds', 'index.buildButton') + '</button>';
			},
		});
	}

	const config = {
		columns,
		container: '#feeds-vue-admin-table',
		fullPane: false,
		tableData,
	};

	if (canEdit) {
		config.deleteAction = 'product-feeds/feeds/delete';
		config.reorderAction = 'product-feeds/feeds/reorder';
		config.reorderSuccessMessage = Craft.t('product-feeds', 'index.reordered');
		config.reorderFailMessage = Craft.t('product-feeds', 'index.reorderFailed');
	}

	new Craft.VueAdminTable(config);
})();
