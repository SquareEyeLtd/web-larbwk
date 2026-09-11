<?php
/**
 * Programme page template for the old layout (?variant=old). Swapped in by
 * law_po_template_include(). The LIST branch of parts/calendar-body.php as it
 * was: hero, the committee note, the controls with the day links inside them,
 * the results. The old class on the wrapper is what assets/programme-old.css
 * scopes on.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$law_po_committee = law_calendar_is_committee();

$law_po_hero = array();
if ( ! $law_po_committee ) {
	$law_po_hero['title'] = function_exists( 'law_calendar_hero_title' ) ? law_calendar_hero_title() : 'Calendar of Events';
}

get_header();
?>

<?php if ( have_posts() ) : while ( have_posts() ) : the_post(); ?>
<?php get_template_part( 'parts/layout/hero-title', null, $law_po_hero ); ?>

<section class="page-section">
	<div class="grid-container">
		<div class="law-cal law-cal--old<?php echo $law_po_committee ? ' law-cal--committee' : ''; ?>">

			<?php if ( $law_po_committee ) : ?>
				<p class="law-cal__note">All submissions. The public programme shows approved events only.</p>
			<?php endif; ?>

			<?php law_po_part( 'controls' ); ?>

			<div class="law-cal-events" id="law-cal-events" aria-live="polite">
				<?php law_po_part( 'events', array( 'show_status' => $law_po_committee ) ); ?>
			</div>

		</div>
	</div>
</section>
<?php endwhile; endif; ?>

<?php
get_footer();
