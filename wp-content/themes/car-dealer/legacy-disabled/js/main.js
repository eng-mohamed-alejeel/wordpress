
	jQuery(document).ready(function($) {
		// فلترة السيارات
		$('.filter-btn').on('click', function() {
			$('.filter-btn').removeClass('active');
			$(this).addClass('active');

			var filter = $(this).data('filter');

			if (filter === 'all') {
				$('.car-card').show();
			} else {
				$('.car-card').hide();
				$('.car-card[data-"category"] = "' + filter + '"').show();
			}
		});

		// تأثير التمرير السلس
		$('.smooth-scroll').on('click', function(e) {
			e.preventDefault();
			var target = $(this).attr('href');
			$('html, body').animate({
				scrollTop: $(target).offset().top - 70
			}, 1000);
		});
	});
	