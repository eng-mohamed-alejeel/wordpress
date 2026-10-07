/* WordPress media, editors and controls require Underscore's global API. */
(function (window) {
	'use strict';
	var underscore = window._;
	if (!underscore || typeof underscore.pluck !== 'function' ||
		typeof underscore.where !== 'function' || typeof underscore.contains !== 'function') {
		return;
	}
	var descriptor = Object.getOwnPropertyDescriptor(window, '_');
	if (descriptor && !descriptor.configurable) {
		return;
	}
	var warned = false;
	Object.defineProperty(window, '_', {
		configurable: true,
		enumerable: descriptor ? descriptor.enumerable : true,
		get: function () { return underscore; },
		set: function (library) {
			if (library === underscore) { return; }
			if (!warned && window.console) {
				window.console.warn('WordPress: prevented another script from replacing its Underscore library.');
				warned = true;
			}
		}
	});
})(window);
