<?php
/* @var array $scriptProperties */
/* @var Tickets $Tickets */
$Tickets = $modx->getService('tickets','Tickets',$modx->getOption('tickets.core_path',null,$modx->getOption('core_path').'components/tickets/').'model/tickets/',$scriptProperties);
$Tickets->initialize($modx->context->key, $scriptProperties);

$scriptProperties['nestedChunkPrefix'] = 'tickets_';
/** @var pdoFetch $pdoFetch */
$fqn = $modx->getOption('pdoFetch.class', null, 'pdotools.pdofetch', true);
if (!$pdoClass = $modx->loadClass($fqn, '', false, true)) {return false;}
$pdoFetch = new $pdoClass($modx, $scriptProperties);
$pdoFetch->addTime('pdoTools loaded');

if (empty($id)) {$id = $modx->resource->id;}
/** @var Ticket|modResource $ticket */
if (!$ticket = $modx->getObject('modResource', $id)) {
	return 'Could not load resource with id = '.$id;
}

$class = $ticket instanceof Ticket
	? 'Ticket'
	: 'modResource';

$data = $ticket->toArray();
$vote = $pdoFetch->getObject('TicketVote', array('id' => $ticket->id, 'class' => 'Ticket', 'createdby' => $modx->user->id), array('select' => 'value', 'sortby' => 'id'));
if (!empty($vote)) {
	$data['vote'] = $vote['value'];
}

if ($class != 'Ticket') {
	// Rating
	if (!$modx->user->id || $modx->user->id == $ticket->createdby) {
		$data['voted'] = 0;
	}
	else {
		$q = $modx->newQuery('TicketVote');
		$q->where(array('id' => $ticket->id, 'createdby' => $modx->user->id, 'class' => 'Ticket'));
		$q->select('`value`');
		$tstart = microtime(true);
		if ($q->prepare() && $q->stmt->execute()) {
			$modx->startTime += microtime(true) - $tstart;
			$modx->executedQueries ++;
			$voted = $q->stmt->fetchColumn();
			if ($voted > 0) {$voted = 1;}
			elseif ($voted < 0) {$voted = -1;}
			$data['voted'] = $voted;
		}
	}
	$data['can_vote'] = $data['voted'] === false && $modx->user->id && $modx->user->id != $ticket->createdby;

	$data = array_merge($ticket->getProperties('tickets'), $data);
	if (!isset($data['rating'])) {
		$data['rating'] = $data['rating_total'] = $data['rating_plus'] = $data['rating_minus'] = 0;
	}

	// Views
	$data['views'] = 0;
	$q = $modx->newQuery('modResource', $ticket->id);
	$q->leftJoin('TicketView','TicketView', "`TicketView`.`parent` = `modResource`.`id`");
	$q->select('COUNT(`TicketView`.`parent`) as `views`');
	$tstart = microtime(true);
	if ($q->prepare() && $q->stmt->execute()) {
		$modx->startTime += microtime(true) - $tstart;
		$modx->executedQueries ++;
		$data['views'] = (integer) $q->stmt->fetchColumn();
	}

	// Comments
	$data['comments'] = 0;
	$q = $modx->newQuery('TicketThread', array('name' => 'resource-'.$ticket->id));
	$q->leftJoin('TicketComment','TicketComment', "`TicketThread`.`id` = `TicketComment`.`thread` AND `TicketComment`.`published` = 1");
	$q->select('COUNT(`TicketComment`.`id`) as `comments`');
	$tstart = microtime(true);
	if ($q->prepare() && $q->stmt->execute()) {
		$modx->startTime += microtime(true) - $tstart;
		$modx->executedQueries ++;
		$data['comments'] = (integer) $q->stmt->fetchColumn();
	}

	// Date ago
	$data['date_ago'] = $Tickets->dateFormat($data['createdon']);
}

if ($data['rating'] > 0) {
	$data['rating'] = '+'.$data['rating'];
	$data['rating_positive'] = 1;
}
elseif ($data['rating'] < 0) {
	$data['rating_negative'] = 1;
}

if (!$modx->user->id || $modx->user->id == $ticket->createdby) {
	$data['cant_vote'] = 1;
}
elseif (array_key_exists('vote', $data)) {
	if ($data['vote'] == '') {
		$data['can_vote'] = 1;
	}
	elseif ($data['vote'] > 0) {
		$data['voted_plus'] = 1;
		$data['cant_vote'] = 1;
	}
	elseif ($data['vote'] < 0) {
		$data['voted_minus'] = 1;
		$data['cant_vote'] = 1;
	}
	else {
		$data['voted_none'] = 1;
		$data['cant_vote'] = 1;
	}
}
$data['active'] = (integer) !empty($data['can_vote']);
$data['inactive'] = (integer) !empty($data['cant_vote']);

if (!empty($getSection)) {
	$fields = $modx->getFieldMeta('modResource');
	unset($fields['content'], $fields['id']);
	$section = $pdoFetch->getObject('modResource', $ticket->parent, array('select' => implode(',', array_keys($fields))));
	foreach ($section as $k => $v) {
		$data['section.'.$k] = $v;
	}
}
if (!empty($getUser)) {
	$fields = $modx->getFieldMeta('modUserProfile');
	$user = $pdoFetch->getObject('modUserProfile', array('internalKey' => $ticket->createdby), array(
		'innerJoin' => array(
			'modUser' => array('class' => 'modUser', 'on' => '`modUserProfile`.`internalKey` = `modUser`.`id`')
		),
		'select' => array(
			'modUserProfile' => implode(',', array_keys($fields)),
			'modUser' => 'username',
		)
	));
	$data = array_merge($data, $user);
}
$data['id'] = $ticket->id;

return !empty($tpl)
	? $pdoFetch->getChunk($tpl, $data)
	: $pdoFetch->getChunk('', $data);
