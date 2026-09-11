<?php
/**
 * An event's sessions as a vertical timeline.
 *
 * Every single event view uses this: the flagship conference's day-long agenda
 * and an ordinary hosted event's two or three sessions alike (Denis, 11
 * September 2026). This replaced an accordion, which collapsed the one thing a
 * reader scans a programme for behind summaries they then had to open one by
 * one. The only difference between the two pages is the heading over it.
 *
 * Design decisions, from the established pattern for conference agendas:
 *
 * - An ORDERED list. The agenda is a chronological sequence, so <ol> carries
 *   that meaning to a screen reader, and each session is one <li>.
 * - Real <time datetime> elements, machine-readable, with the human 12-hour
 *   label as their text (law_calendar_time_12h(), the same format the event's
 *   own Time fact uses, rather than the stored 24-hour time_label).
 * - ONE left-aligned rail, never the "cards alternating either side of a
 *   centre line" variant. Alternating layouts break down on a phone, and they
 *   cost the reader the single vertical line their eye follows down a
 *   schedule.
 * - The line and the markers are CSS pseudo-elements, not markup: they are
 *   decoration, and the time beside them is the real information.
 * - Every session gets the same marker and the same weight. A previous version
 *   guessed that a session with no description and nobody speaking was a break
 *   and greyed it out; Denis had that removed on 11 September 2026, because the
 *   guess is wrong as often as it is right (a real session whose blurb has not
 *   been written yet reads as a coffee break) and nothing in the data says
 *   which is which.
 *
 * - The flagship conference renders the whole section reversed inside a filled
 *   navy panel ('panel' => true, from templates/flagship-event.php). Its agenda
 *   is the substance of that page, and the panel is what marks it out as the
 *   one paid, day-long event rather than a list of sessions like any other's
 *   (Denis, 11 September 2026). The description stays ABOVE the panel, on the
 *   white page, so the box contains the running order and nothing else. The
 *   speaker cards inside it reverse with it (calendar.css).
 *
 * get_template_part( 'parts/events/session-timeline', null, array(
 *   'sessions' => law_event_session_rows( $event_id ), // required
 *   'heading'  => 'Agenda',
 *   'panel'    => true, // reversed onto navy; default is the plain white page
 * ) );
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$args = isset( $args ) && is_array( $args ) ? $args : array();

$law_tl_sessions = isset( $args['sessions'] ) && is_array( $args['sessions'] ) ? $args['sessions'] : array();
$law_tl_heading  = (string) ( $args['heading'] ?? __( 'Agenda', 'law' ) );
$law_tl_panel    = ! empty( $args['panel'] );

if ( ! $law_tl_sessions ) {
	return;
}
?>
<section class="law-cal-sessions law-timeline-section<?php echo $law_tl_panel ? ' law-timeline-section--panel' : ''; ?>" aria-labelledby="law-timeline-heading">
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

			// The same fallback order the rest of the module uses, so a session
			// with no title reads the same wherever it is rendered.
			if ( '' === $law_tl_title ) {
				$law_tl_title = (string) ( $law_tl_session['time_label'] ?? '' ) ?: __( 'Session', 'law' );
			}
			?>
			<li class="law-timeline__item">
				<?php if ( '' !== $law_tl_start ) : ?>
					<p class="law-timeline__time">
						<time datetime="<?php echo esc_attr( $law_tl_start ); ?>"><?php echo esc_html( law_calendar_time_12h( $law_tl_start ) ); ?></time>
						<?php if ( '' !== $law_tl_end ) : ?>
							<span class="law-timeline__dash" aria-hidden="true">–</span>
							<time datetime="<?php echo esc_attr( $law_tl_end ); ?>"><?php echo esc_html( law_calendar_time_12h( $law_tl_end ) ); ?></time>
						<?php endif; ?>
					</p>
				<?php endif; ?>
				<?php
				// Two columns from 64em when the item has both something to read and
				// somebody speaking: the description on the left, that session's
				// speakers on the right (Denis, 11 September 2026). The title spans
				// both (calendar.css).
				$law_tl_split = '' !== trim( $law_tl_desc ) && $law_tl_people;
				?>
				<div class="law-timeline__content<?php echo $law_tl_split ? ' law-timeline__content--split' : ''; ?>">
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
