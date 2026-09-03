<?php
/**
 * Shared Full list / Day markup for public and committee calendars.
 *
 * Set $law_cal_show_status before including.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$law_cal_show_status = ! empty( $law_cal_show_status );

$page_id = get_queried_object_id();
$calendar_blocked = function_exists( 'members_can_current_user_view_post' )
	&& $page_id
	&& ! members_can_current_user_view_post( $page_id );

$event_id = $calendar_blocked ? 0 : law_calendar_requested_event_id();
$event    = $event_id ? law_calendar_event_by_id( $event_id ) : null;

get_header();

$days = $calendar_blocked ? array() : law_calendar_week_days();

$hero_args = array( 'is_event' => (bool) $event );
if ( ! empty( $law_cal_hero_title ) ) {
	$hero_args['title'] = (string) $law_cal_hero_title;
}
?>

<?php if ( have_posts() ) : while ( have_posts() ) : the_post(); ?>
<?php get_template_part( 'parts/layout/hero-title', null, $hero_args ); ?>

<section class="page-section">
	<div class="grid-container">
		<?php if ( $calendar_blocked ) : ?>
			<div class="law-cal">
				<?php echo wp_kses_post( members_get_post_error_message( get_the_ID() ) ); ?>
			</div>
		<?php else : ?>
		<div class="law-cal<?php echo $law_cal_show_status ? ' law-cal--committee' : ''; ?>">

			<?php if ( $law_cal_show_status && ! $event ) : ?>
				<p class="law-cal__note">All submissions. The public programme shows approved events only.</p>
			<?php endif; ?>

			<?php if ( $event ) : ?>

				<a class="law-cal-detail__back" href="<?php echo esc_url( law_calendar_url() ); ?>">Back to programme</a>

				<?php
				$event_date_label = $event['date'] ? ( $days[ $event['date'] ] ?? $event['date'] ) : 'Slot not confirmed';
				$host_list        = $event['host'] ? array_filter( array_map( 'trim', preg_split( '/;/', $event['host'] ) ) ) : array();
				?>

				<article class="law-cal-detail law-cal-card">
					<div class="law-cal-detail__layout">
						<aside class="law-cal-detail__aside" aria-label="<?php esc_attr_e( 'Event details', 'law' ); ?>">
							<?php if ( $law_cal_show_status ) : ?>
								<?php law_calendar_status_badge( $event ); ?>
							<?php endif; ?>
							<div class="law-cal-detail__fact">
								<h3>Date</h3>
								<p><?php echo esc_html( $event_date_label ); ?></p>
							</div>
							<div class="law-cal-detail__fact">
								<h3>Time</h3>
								<p><?php echo esc_html( $event['time_label'] ); ?></p>
							</div>
							<?php if ( $host_list ) : ?>
								<div class="law-cal-detail__fact">
									<h3>Host organisations</h3>
									<ul>
										<?php foreach ( $host_list as $host ) : ?>
											<li><?php echo esc_html( $host ); ?></li>
										<?php endforeach; ?>
									</ul>
								</div>
							<?php endif; ?>
							<?php if ( ! empty( $event['tickets'] ) ) : ?>
								<div class="law-cal-detail__fact">
									<h3>Tickets available</h3>
									<p><?php echo esc_html( number_format_i18n( $event['tickets'] ) ); ?></p>
								</div>
							<?php endif; ?>
							<?php if ( $event['type'] ) : ?>
								<div class="law-cal-detail__fact">
									<h3>Type</h3>
									<p><?php echo esc_html( $event['type'] ); ?></p>
								</div>
							<?php endif; ?>
							<?php if ( ! empty( $event['sectors'] ) ) : ?>
								<div class="law-cal-detail__fact">
									<h3>Sector</h3>
									<ul>
										<?php foreach ( $event['sectors'] as $sector ) : ?>
											<li><?php echo esc_html( $sector ); ?></li>
										<?php endforeach; ?>
									</ul>
								</div>
							<?php endif; ?>
						</aside>

						<div class="law-cal-detail__main">
							<h1 class="law-cal-detail__title">
								<?php echo esc_html( $event['title'] ); ?>
								<?php law_calendar_edit_link( $event ); ?>
							</h1>
							<div class="law-cal-detail__body">
								<?php echo wp_kses_post( wpautop( $event['description'] ) ); ?>
							</div>
							<?php if ( $event['venue'] ) : ?>
								<section class="law-cal-venue" aria-labelledby="law-cal-venue-heading">
									<h2 id="law-cal-venue-heading" class="law-cal-acc__heading">Venue</h2>
									<p class="law-cal-venue__address"><?php echo esc_html( $event['venue'] ); ?></p>
									<?php
									$maps_url  = law_calendar_maps_url( $event['venue'] );
									$embed_url = law_calendar_maps_embed_url( $event['venue'] );
									$show_map  = law_calendar_venue_is_mappable( $event['venue'] ) && $embed_url;
									?>
									<?php if ( $show_map ) : ?>
										<div class="law-cal-venue__map">
											<iframe
												title="<?php echo esc_attr( sprintf( __( 'Map of %s', 'law' ), $event['venue'] ) ); ?>"
												src="<?php echo esc_url( $embed_url ); ?>"
												loading="lazy"
												referrerpolicy="no-referrer-when-downgrade"
												allowfullscreen
											></iframe>
										</div>
										<?php if ( $maps_url ) : ?>
											<p class="law-cal-venue__open">
												<a href="<?php echo esc_url( $maps_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open in Google Maps', 'law' ); ?></a>
											</p>
										<?php endif; ?>
									<?php endif; ?>
								</section>
							<?php endif; ?>
							<?php if ( ! empty( $event['sessions'] ) ) : ?>
								<section class="law-cal-sessions" aria-labelledby="law-cal-sessions-heading">
									<h2 id="law-cal-sessions-heading" class="law-cal-acc__heading">Sessions</h2>
									<?php foreach ( $event['sessions'] as $session ) : ?>
										<?php
										$session_label = trim( $session['title'] );
										if ( '' === $session_label ) {
											$session_label = $session['time_label'] ? $session['time_label'] : __( 'Session', 'law' );
										}
										?>
										<details class="law-cal-session" name="law-cal-sessions">
											<summary class="law-cal-session__summary">
												<span class="law-cal-session__heading">
													<span class="law-cal-session__title"><?php echo esc_html( $session_label ); ?></span>
													<?php if ( $session['time_label'] && $session['time_label'] !== $session_label ) : ?>
														<span class="law-cal-session__time"><?php echo esc_html( $session['time_label'] ); ?></span>
													<?php endif; ?>
												</span>
											</summary>
											<div class="law-cal-session__panel">
												<?php if ( $session['description'] ) : ?>
													<div class="law-cal-session__body">
														<?php echo wp_kses_post( wpautop( $session['description'] ) ); ?>
													</div>
												<?php endif; ?>
												<?php if ( ! empty( $session['speakers'] ) ) : ?>
													<ul class="law-cal-session__speakers">
														<?php foreach ( $session['speakers'] as $session_speaker ) : ?>
															<li>
																<span class="law-cal-session__photo">
																	<?php if ( ! empty( $session_speaker['photo'] ) ) : ?>
																		<img src="<?php echo esc_url( $session_speaker['photo'] ); ?>" alt="<?php echo esc_attr( $session_speaker['name'] ); ?>" width="32" height="32">
																	<?php endif; ?>
																</span>
																<span class="law-cal-session__speaker-body">
																	<?php if ( ! empty( $session_speaker['url'] ) ) : ?>
																		<a href="<?php echo esc_url( $session_speaker['url'] ); ?>"><?php echo esc_html( $session_speaker['name'] ); ?></a>
																	<?php else : ?>
																		<?php echo esc_html( $session_speaker['name'] ); ?>
																	<?php endif; ?>
																	<?php
																	$role = array_filter( array( $session_speaker['job_title'], $session_speaker['organisation'] ) );
																	if ( $role ) {
																		echo '<span class="law-cal-session__role">' . esc_html( implode( ', ', $role ) ) . '</span>';
																	}
																	?>
																</span>
															</li>
														<?php endforeach; ?>
													</ul>
												<?php endif; ?>
											</div>
										</details>
									<?php endforeach; ?>
								</section>
							<?php endif; ?>
							<?php if ( empty( $event['sessions'] ) && ! empty( $event['speakers'] ) ) : ?>
								<section class="law-cal-acc">
									<h2 class="law-cal-acc__heading">Speakers</h2>
									<ul class="law-cal-speakers">
										<?php foreach ( $event['speakers'] as $speaker ) : ?>
											<li>
												<span class="law-cal-speakers__photo">
													<?php if ( ! empty( $speaker['photo'] ) ) : ?>
														<img src="<?php echo esc_url( $speaker['photo'] ); ?>" alt="<?php echo esc_attr( $speaker['name'] ); ?>" width="48" height="48">
													<?php endif; ?>
												</span>
												<span class="law-cal-speakers__body">
													<?php if ( $speaker['url'] ) : ?>
														<a href="<?php echo esc_url( $speaker['url'] ); ?>"><?php echo esc_html( $speaker['name'] ); ?></a>
													<?php else : ?>
														<?php echo esc_html( $speaker['name'] ); ?>
													<?php endif; ?>
													<?php
													$role = array_filter( array( $speaker['job_title'], $speaker['organisation'] ) );
													if ( $role ) {
														echo '<span class="law-cal-speakers__role">' . esc_html( implode( ', ', $role ) ) . '</span>';
													}
													?>
												</span>
											</li>
										<?php endforeach; ?>
									</ul>
								</section>
							<?php endif; ?>
						</div>
					</div>
				</article>

			<?php else : ?>

				<?php get_template_part( 'parts/calendar-filters' ); ?>

				<div class="law-cal-events" id="law-cal-events" aria-live="polite">
					<?php get_template_part( 'parts/calendar-events', null, array( 'show_status' => $law_cal_show_status ) ); ?>
				</div>

			<?php endif; ?>

		</div>
		<?php endif; ?>
	</div>
</section>
<?php endwhile; endif; ?>

<?php
get_footer();
