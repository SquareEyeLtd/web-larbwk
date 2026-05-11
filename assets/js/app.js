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
  function updateNavAffix() {
    if ($(document).scrollTop() > 5) {
      $('.nav').addClass('affix');
    } else {
      $('.nav').removeClass('affix');
    }
  }

  // Set state on page load.
  updateNavAffix();

$('.navTrigger').click(function () {

    if ( $(this).hasClass('active') ) {
      $(this).removeClass('active');
      $("#mainListDiv").slideUp(800);
      $("#mainListDiv").removeClass("show_list");
     
    } else {
      $(this).addClass('active');
      $("#mainListDiv").addClass("show_list");
      $("#mainListDiv").slideDown(800);
      $('.nav').addClass('affix');

    }
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