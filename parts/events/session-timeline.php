<?php
/**
 * The flagship conference's agenda as a vertical timeline.
 *
 * The accordion the ordinary single event view uses (parts/calendar-body.php)
 * is right for an event with two or three sessions the reader may want to open;
 * it is wrong for a day-long conference, where the running order IS the
 * content and collapsing eight items behind summaries hides the whole
 * programme. So the flagship renders every session open, on a timeline.
 *
 * Design decisions, from the established pattern for conference agendas:
 *
 * - An ORDERED list. The agenda is a chronological sequence, so <ol> carries
 *   that meaning to a screen reader, and each session is one <li>.
 * - Real <time datetime> elements, machine-readable, with the human 12-hour
 *   label as their text (law_calendar_time_12h(), the same format the event's
 *   own Time fact uses, rather than the 24-hour time_label the accordion
 *   prints).
 * - ONE left-aligned rail, never the "cards alternating either side of a
 *   centre line" variant. Alternating layouts break down on a phone, and they
 *   cost the reader the single vertical line their eye follows down a
 *   schedule.
 * - The line and the markers are CSS pseudo-elements, not markup: they are
 *   decoration, and the time beside them is the real information.
 * - Breaks are de-emphasised (a smaller, hollow marker and lighter type). A
 *   session with no description and no speakers is a break — registration,
 *   coffee, lunch — and giving it the same weight as a keynote flattens the
 *   shape of the day. This is the "vary the marker to show what matters"
 *   principle applied to what the data actually says, rather than to a field
 *   nobody would maintain.
 *
 * get_template_part( 'parts/events/session-timeline', null, array(
 *   'sessions' => law_event_session_rows( $event_id ), // required
 *   'heading'  => 'Agenda',
 * ) );
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$args = isset( $args ) && is_array( $args ) ? $args : array();

$law_tl_sessions = isset( $args['sessions'] ) && is_array( $args['sessions'] ) ? $args['sessions'] : array();
$law_tl_heading  = (string) ( $args['heading'] ?? __( 'Agenda', 'law' ) );

if ( ! $law_tl_sessions ) {
	return;
}
?>
<section class="law-cal-sessions law-timeline-section" aria-labelledby="law-timeline-heading">
	<h2 id="law-timeline-heading" class="law-cal-acc__heading"><?php echo esc_html( $law_tl_heading ); ?></h2>
	<ol class="law-timeline">
		<?php foreach ( $law_tl_sessions as $law_tl_session ) : ?>
			<?php
			$law_tl_title = trim( (string) ( $law_tl_session['title'] ?? '' ) );
			$law_tl_start = (string) ( $law_tl_session['start'] ?? '' );
			$law_tl_end   = (string) ( $law_tl_session['end'] ?? '' );
			$law_tl_desc  = (string) ( $law_tl_session['description'] ?? '' );
			$law_tl_people = isset( $law_tl_session['speakers'] ) && is_array( $law_tl_session['speakers'] )
				? $law_tl_session['speakers']
				: array();

			// Same fallback order as the accordion, so a session with no title
			// reads the same wherever it is rendered.
			if ( '' === $law_tl_title ) {
				$law_tl_title = (string) ( $law_tl_session['time_label'] ?? '' ) ?: __( 'Session', 'law' );
			}

			// A break: nothing to read and nobody speaking.
			$law_tl_is_break = '' === trim( wp_strip_all_tags( $law_tl_desc ) ) && ! $law_tl_people;
			?>
			<li class="law-timeline__item<?php echo $law_tl_is_break ? ' law-timeline__item--break' : ''; ?>">
				<?php if ( '' !== $law_tl_start ) : ?>
					<p class="law-timeline__time">
						<time datetime="<?php echo esc_attr( $law_tl_start ); ?>"><?php echo esc_html( law_calendar_time_12h( $law_tl_start ) ); ?></time>
						<?php if ( '' !== $law_tl_end ) : ?>
							<span class="law-timeline__dash" aria-hidden="true">–</span>
							<time datetime="<?php echo esc_attr( $law_tl_end ); ?>"><?php echo esc_html( law_calendar_time_12h( $law_tl_end ) ); ?></time>
						<?php endif; ?>
					</p>
				<?php endif; ?>
				<div class="law-timeline__content">
					<h3 class="law-timeline__title"><?php echo esc_html( $law_tl_title ); ?></h3>
					<?php if ( '' !== trim( $law_tl_desc ) ) : ?>
						<div class="law-timeline__body">
							<?php echo wp_kses_post( wpautop( $law_tl_desc ) ); ?>
						</div>
					<?php endif; ?>
					<?php if ( $law_tl_people ) : ?>
						<ul class="law-cal-speakers law-cal-speakers--cards law-timeline__speakers">
							<?php foreach ( $law_tl_people as $law_tl_speaker ) : ?>
								<?php get_template_part( 'parts/events/speaker-card', null, array( 'speaker' => $law_tl_speaker ) ); ?>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>
				</div>
			</li>
		<?php endforeach; ?>
	</ol>
</section>
