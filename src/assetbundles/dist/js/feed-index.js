(function () {
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
				Craft.cp.displayError(error.response.data.message);
			})
			.finally(() => {
				button.classList.remove('loading');
			});
	});
})();
