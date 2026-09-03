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

if ( $event ) {
	$host_list = $event['host'] ? array_filter( array_map( 'trim', preg_split( '/;/', $event['host'] ) ) ) : array();
	$hero_args = array(
		'title'   => $event['title'],
		'classes' => 'law-event-hero',
		'meta'    => array(
			array( 'label' => 'Date', 'value' => $event['date'] ? law_calendar_day_heading( $event['date'] ) : 'Slot not confirmed' ),
			array( 'label' => 'Time', 'value' => $event['start'] ? law_calendar_event_time_label( $event ) : '' ),
			array( 'label' => 'Location', 'value' => $event['venue'] ),
			array( 'label' => 'Hosted by', 'value' => implode( ', ', $host_list ) ),
			array( 'label' => 'Type', 'value' => $event['type'] ),
			array( 'label' => 'Sector', 'value' => implode( ', ', $event['sectors'] ) ),
			array( 'label' => 'Available tickets', 'value' => $event['tickets'] ? number_format_i18n( $event['tickets'] ) : '' ),
		),
	);
} else {
	$hero_args = array();
	if ( ! empty( $law_cal_hero_title ) ) {
		$hero_args['title'] = (string) $law_cal_hero_title;
	}
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

				<article class="law-cal-detail">
					<div class="grid-x grid-padding-x">
						<div class="large-8 cell">
							<?php if ( $law_cal_show_status || law_calendar_entry_admin_url( $event['id'] ) ) : ?>
								<p class="law-cal-detail__admin">
									<?php if ( $law_cal_show_status ) : ?>
										<?php law_calendar_status_badge( $event ); ?>
									<?php endif; ?>
									<?php law_calendar_edit_link( $event ); ?>
								</p>
							<?php endif; ?>
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
																	<?php else : ?>
																		<span class="law-cal-session__initials" aria-hidden="true"><?php echo esc_html( law_calendar_name_initials( $session_speaker['name'] ) ); ?></span>
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
									<ul class="law-cal-speakers law-cal-speakers--large">
										<?php foreach ( $event['speakers'] as $speaker ) : ?>
											<li>
												<span class="law-cal-speakers__photo">
													<?php if ( ! empty( $speaker['photo'] ) ) : ?>
														<img src="<?php echo esc_url( $speaker['photo'] ); ?>" alt="<?php echo esc_attr( $speaker['name'] ); ?>" loading="lazy">
													<?php else : ?>
														<span class="law-cal-speakers__initials" aria-hidden="true"><?php echo esc_html( law_calendar_name_initials( $speaker['name'] ) ); ?></span>
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
							<div class="law-cal-detail__actions">
								<?php /* Registration is not wired up yet; the button is a placeholder. */ ?>
								<a class="button orange law-event-card__button--register" href="#" aria-disabled="true">
									<?php esc_html_e( 'Register', 'law' ); ?>
									<svg class="law-event-card__arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M14 3h7v7"/><path d="M10 14 21 3"/></svg>
								</a>
								<a class="button" href="<?php echo esc_url( law_calendar_url() ); ?>"><?php esc_html_e( 'Back to events calendar', 'law' ); ?></a>
							</div>
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
