<?php
namespace EPFL\Plugins\Gutenberg\Memento;

/**
 * trims text to a space then adds ellipses if desired
 * @param string $input text to trim
 * @param int $length in characters to trim to
 * @param bool $ellipses if ellipses (...) are to be added
 * @param bool $strip_html if html tags are to be stripped
 * @return string
 */
function trim_text($input, $length, $ellipses = true, $strip_html = true) {
    //strip tags, if desired
    if ($strip_html) {
        $input = strip_tags($input);
    }

    //no need to trim, already shorter than trim length
    if (strlen($input) <= $length) {
        return $input;
    }

    //find last space within length
    $last_space = strrpos(substr($input, 0, $length), ' ');
    $trimmed_text = substr($input, 0, $last_space);

    //add ellipses (...)
    if ($ellipses) {
        $trimmed_text .= '...';
    }

    return $trimmed_text;
}

/**
 * return true is the event is just finished.
 * The period during which an event is "just finished" begins at the end of the event
 * and ends at midnight on the last day of the event.
 * @param string $end_date the end date of the event
 * @param string $end_time the end time of the event
 * @return boolean
 */
function is_just_finished($end_date, $end_time) {

    if (empty($end_time)) {
      return false;
    }

    date_default_timezone_set('Europe/Paris');
    $now = new \DateTime();
    $end_date = new \DateTime($end_date);
    $end_time = new \DateTime($end_time);

    $merge = new \DateTime($end_date->format('Y-m-d') . ' ' . $end_time->format('H:i:s'));
    if ($now->format('Y-m-d') == $merge->format('Y-m-d') && $now > $merge) {
        return true;
    }
    return false;
}

/**
 *
 */
function is_inscription_required($registration) {
    // id=1 => "Registration required"
    return ($registration->id === 1);
}

function get_event() {
    $event = get_query_var('epfl_event');
    return $event;
}

/**
 * Get visual url of event
 */
function get_visual_url($event, $memento_name) {
    $visual_url = "";
    if (empty($event->academic_calendar_category)) {
        if ($event->visual_url) {
            $visual_url = substr($event->visual_url, 0, -11) . '509x286.jpg';
        } else {
            if ($memento_name == 'academic-calendar') {
                $visual_url = "https://memento.epfl.ch/static/img/Others.jpg";
            } else {
                $visual_url = "https://memento.epfl.ch/static/img/default.jpg";
            }
        }
    } else {
        $visual_url = "https://memento.epfl.ch/static/img/";
        $visual_url .= $event->academic_calendar_category->en_label;
        $visual_url .= ".jpg";
    }
    return $visual_url;
}

function get_memento_url($period, $memento_name)
{
	if ($memento_name === MEMENTO_ALL_EVENTS_SLUG) {
		$memento_url = "https://memento.epfl.ch";
	} else {
		$memento_url = "https://memento.epfl.ch/" . $memento_name;
	}

	if ($period === 'past') {
		$now = date("Y-m-d");
		$year = (intval(substr($now, 0, 4)) - 1) . substr($now, 4, 10);
		$memento_url .= "/?period=365&date=" . $year;
	}

	return $memento_url;
}
