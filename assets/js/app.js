jQuery(function ($) {
  $(document).foundation();

  if (typeof WOW === 'function') {
    new WOW().init();
  }



// Target date: December 1, 2025 at 09:00:00
var countdownDate = new Date("December 1, 2025 09:00:00").getTime();
var matchHeightApplied = false;

var x = setInterval(function () {
  var now = new Date().getTime();
  var distance = countdownDate - now;

  // If countdown has ended
  if (distance < 0) {
    //clearInterval(x);
    //$('#countdown').hide();
    return;
  }

  var days = Math.floor(distance / (1000 * 60 * 60 * 24));
  var hours = Math.floor((distance % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
  var minutes = Math.floor((distance % (1000 * 60 * 60)) / (1000 * 60));
  var seconds = Math.floor((distance % (1000 * 60)) / 1000);

  $('#days').text(days);
  $('#hours').text(hours);
  $('#minutes').text(minutes);
  $('#seconds').text(seconds);

  // Apply matchHeight only ONCE and only on large screens
  if (window.innerWidth > 1023 && !matchHeightApplied) {
    if ($.fn.matchHeight) {
      $('.matchbox').matchHeight({ byRow: false });
      matchHeightApplied = true;
    }
  }

  // Optional: If user resizes to a smaller screen, reset the height
  if (window.innerWidth <= 1023 && matchHeightApplied) {
    $('.matchbox').css('height', 'auto');
    matchHeightApplied = false;
  }
}, 200);


 // MatchHeight

  if ($.fn.matchHeight) {
    $('.featured-block').matchHeight();
    $('.block-details p').matchHeight();
  }


 
  // Navigation

  /* The fixed nav's height, published as --law-header-offset for everything
     that has to stop short of the header: html's scroll-padding-top in app.css
     (which is what makes every same-page anchor on the site land below the nav
     instead of under it) and the event form's sticky section nav.

     Set on every page, not only where .law-cal exists as this used to be: any
     page can hold an anchor link.

     Measured in whatever state the nav is currently in. Once the page has
     scrolled at all the nav is affixed and shorter, which is the state that
     matters. Clicking an anchor from the very top measures the taller
     unaffixed nav and so stops a little early, which leaves a small gap rather
     than hiding the target under the header — the safe direction to be wrong. */
  function updateHeaderOffset() {
    var nav = document.querySelector('.nav');
    if (!nav) {
      return;
    }
    var top = Math.max(Math.round(nav.getBoundingClientRect().bottom), 0);
    document.documentElement.style.setProperty('--law-header-offset', top + 'px');
  }

  function updateNavAffix() {
    if ($(document).scrollTop() > 5) {
      $('.nav').addClass('affix');
    } else {
      $('.nav').removeClass('affix');
    }
    updateHeaderOffset();
  }

  // Set state on page load.
  updateNavAffix();
  $(window).on('resize', updateHeaderOffset);
  $('.nav').on('transitionend', function (e) {
    if (!e.originalEvent || e.originalEvent.propertyName === 'padding-top') {
      updateHeaderOffset();
    }
  });

  /* Landing straight on a URL with a fragment (.../#law-cal-venue-heading, say)
     is the one case CSS cannot handle on its own: the browser performs its jump
     before this script has measured the nav, so it uses the static fallback in
     app.css and can stop in the wrong place. Re-run the scroll once the real
     offset is known. Instant, not smooth: this is correcting the browser's own
     jump, not animating a navigation the visitor asked for. */
  if (window.location.hash && window.location.hash.length > 1) {
    $(window).on('load', function () {
      var target;
      try {
        target = document.querySelector(window.location.hash);
      } catch (e) {
        return;
      }
      if (target) {
        updateHeaderOffset();
        target.scrollIntoView({ behavior: 'auto', block: 'start' });
      }
    });
  }

$('.navTrigger').click(function () {

    if ( $(this).hasClass('active') ) {
      $(this).removeClass('active');
      $(this).attr('aria-expanded', 'false');
      $("#mainListDiv").slideUp(800);
      $("#mainListDiv").removeClass("show_list");
     
    } else {
      $(this).addClass('active');
      $(this).attr('aria-expanded', 'true');
      // Tell header-nav.js to close the account dropdown: the burger's
      // full-screen sheet and an open account panel would otherwise overlap.
      document.dispatchEvent(new CustomEvent('law:menu-open', { detail: 'burger' }));
      $("#mainListDiv").addClass("show_list");
      $("#mainListDiv").slideDown(800, updateHeaderOffset);
      $('.nav').addClass('affix');

    }
    updateHeaderOffset();
});

$('.filter-button a[data-tab]').on('click', function (e) {
    e.preventDefault();

    var target = $(this).data('tab');
    var newTab = $('#' + target);
    var currentTab = $('.tab-section.active');

    if (newTab.attr('id') === currentTab.attr('id')) {
        return; // Skip if clicking same tab
    }

    // Update active button state
    $('.filter-button a[data-tab]').removeClass('active');
    $(this).addClass('active');

    // Fade out current tab, then fade in new one
    currentTab.fadeOut(200, function () {
        currentTab.removeClass('active');

        newTab.fadeIn(200, function () {
            newTab.addClass('active');
        });
    });
});

// Sort Bookings by Date
function sortBookings() {
    var $container = $('.booking-items');
    var $items = $container.find('.item-details');

    if (!$items.length) return;

    var items = $items.get().sort(function(a, b) {
        var dateA = new Date($(a).find('[data-event-date]').data('event-date'));
        var dateB = new Date($(b).find('[data-event-date]').data('event-date'));
        return dateA - dateB;
    });

    $.each(items, function(i, item) {
        $container.append(item);
    });
}

sortBookings();

  $(window).on('scroll', updateNavAffix);
});


/* Limit events by this year's dates ________________________________________________________ */

gform.addFilter('gform_datepicker_options_pre_init', function(optionsObj, formId, fieldId) {
    if ( formId === 2 && fieldId === 18 ) { // adjust IDs
        optionsObj.minDate = new Date(2026, 11, 30);  // 1 Jan 2025
        optionsObj.maxDate = new Date(2026, 12, 04); // 31 Dec 2025
    }
    return optionsObj;
});


/* Allow drag and drop on Advanced Select fields ________________________________________________________ */

window.gform.addFilter( 'gpadvs_settings', function( settings, gpadvs ) {
	// Target a specific form/field, or remove the check to apply globally
	if ( gpadvs.formId == 12 && gpadvs.fieldId == 4 ) {
		settings.plugins = settings.plugins || {};
		settings.plugins.drag_drop = {};
	}
	return settings;
} );