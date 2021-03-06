<?php

namespace BM2\SiteBundle\Service;

use BM2\SiteBundle\Entity\Action;
use BM2\SiteBundle\Entity\Battle;
use BM2\SiteBundle\Entity\Character;
use BM2\SiteBundle\Entity\Settlement;
use BM2\SiteBundle\Entity\BattleGroup;
use BM2\SiteBundle\Twig\GameTimeExtension;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManager;

use CrEOF\Spatial\PHP\Types\Geometry\Point;


class ActionResolution {

	private $em;
	private $appstate;
	private $history;
	private $dispatcher;
	private $generator;
	private $geography;
	private $interactions;
	private $communication;
	private $politics;
	private $military;
	private $characters;
	private $permissions;
	private $gametime;

	private $max_progress = 5; // maximum number of actions to resolve in each background progression call
	private $debug=100;
	private $speedmod = 1.0;


	public function __construct(EntityManager $em, AppState $appstate, History $history, Dispatcher $dispatcher, Generator $generator, Geography $geography, Interactions $interactions, Communication $communication, Politics $politics, Military $military, PermissionManager $permissions, GameTimeExtension $gametime) {
		$this->em = $em;
		$this->appstate = $appstate;
		$this->history = $history;
		$this->dispatcher = $dispatcher;
		$this->generator = $generator;
		$this->geography = $geography;
		$this->interactions = $interactions;
		$this->communication = $communication;
		$this->politics = $politics;
		$this->military = $military;
		$this->permissions = $permissions;
		$this->gametime = $gametime;
		$this->characters = new ArrayCollection();

		$this->speedmod = (float)$this->appstate->getGlobal('travel.speedmod', 1.0);
	}

	public function progress() {
		$query = $this->em->createQuery("SELECT a FROM BM2SiteBundle:Action a WHERE a.complete IS NOT NULL AND a.complete < :now");
		$query->setParameter('now', new \DateTime("now"));
		$query->setMaxResults($this->max_progress);
		foreach ($query->getResult() as $action) {
			$this->resolve($action);
		}
	}

	public function queue(Action $action, $neverimmediate=false) {
		$action->setStarted(new \DateTime("now"));

		if ($neverimmediate || $action->getComplete() != null) {
			$immediate=false;
		} else {
			$set = $this->appstate->getGlobal('immediateActions');
			if ($set && in_array($set, array(true, 'true', 't', '1'))) { $immediate=true; } else { $immediate=false; }			
		}
		if ($immediate) {
			// do if immediate actions are enabled
			// FIXME: some actions cannot be resolved like this - like the battle and settlement attack ones!
			$success = $this->resolve($action);
		} else {
			// store in database and queue
			$success = true;
			$max=0;
			foreach ($action->getCharacter()->getActions() as $act) {
				if ($act->getPriority()>$max) {
					$max=$act->getPriority();
				}
			}
			$action->setPriority($max+1);

			// some defaults, otherwise I'd have to set it explicitly everywhere
			if (null===$action->getHidden()) { $action->setHidden(false); }
			if (null===$action->getHourly()) { $action->setHourly(false); }
			if (null===$action->getCanCancel()) { $action->setCanCancel(true); }
			$this->em->persist($action);
		}

		$this->em->flush();

		return array('success'=>$success, 'immediate'=>$immediate);
	}

	public function resolve(Action $action) {
		$type = strtr($action->getType(), '.', '_');

		if (method_exists(__CLASS__, $type)) {
			if ($char = $action->getCharacter()) {
				$this->characters->add($char);
				$this->dispatcher->setCharacter($char);
			}
			$this->$type($action);
			return true;
		} else {
			$this->remove($action);
			return false;
		}
	}

	public function update(Action $action) {
		$type = strtr($action->getType(), '.', '_');

		$up = 'update_'.$type;
		if (method_exists(__CLASS__, $up)) {
			return $this->$up($action);
		}
		return false;
	}

	public function joinBattle(Character $character, BattleGroup $group, $timer_recalc=true, $forced=false) {
		$battle = $group->getBattle();
		$soldiers = count($character->getActiveSoldiers());

		// make sure we are only on one side, and send messages to others involved in this battle
		foreach ($battle->getGroups() as $mygroup) {
			$mygroup->removeCharacter($character);

			foreach ($mygroup->getCharacters() as $char) {
				$this->history->logEvent(
					$char,
					'event.military.battlejoin',
					array('%soldiers%'=>$soldiers, '%link-character%'=>$character->getId()),
					History::MEDIUM, false, 12
				);
			}
		}
		$group->addCharacter($character);

		$action = new Action;
		$action->setBlockTravel(true);
		$action->setCharacter($character)->setTargetBattlegroup($group)->setTargetSettlement($battle->getSettlement())->setCanCancel(false)->setHidden(false);
		if ($battle->getSettlement() && $group->isAttacker()) {
			$action->setType('settlement.attack');
		} else {
			$action->setType('military.battle');
		}
		if ($forced) {
			$action->setStringValue('forced');
		}
		$result = $this->queue($action);

		$character->setTravelLocked(true);

		if ($timer_recalc) {
			$this->military->recalculateBattleTimer($battle);
		}

		// join battle message group
		$this->communication->joinGroup($character, $battle->getMessageGroup(), false);
	}


	/* ========== Resolution Methods ========== */

	// TODO: time counter, etc.

	// TODO: messages are mixed, sometimes 2nd person (you have...) and sometimes 3rd (he has...)
	//      --> see note in MessageTranslateExtension

	private function remove(Action $action) {
		// this is just a placeholder action marked for removal, so let's do exactly that (it's our workaround to Doctrine's broken cascades)
		$this->em->remove($action);
	}

	private function check_settlement_take(Action $action) {
		$settlement = $action->getTargetSettlement();
		$this->dispatcher->setCharacter($action->getCharacter());
		$test = $this->dispatcher->controlTakeTest(false, false);
		if (!isset($test['url'])) {
			$this->history->logEvent(
				$action->getCharacter(),
				'multi',
				array('events'=>array('resolution.take.failed', 'resolution.'.$test['description']),
				 '%link-settlement%'=>$settlement->getId()),
				History::LOW, false, 30
			);
			$this->history->logEvent(
				$settlement,
				'event.settlement.take.stopped',
				array('%link-character%'=>$action->getCharacter()->getId()),
				History::HIGH, true, 20
			);
			return false;
		} else {
			return true;
		}
	}

	private function update_settlement_take(Action $action) {
		// recalculate time
		if ($this->check_settlement_take($action)) {
			$now = new \DateTime("now");
			$old_time = $action->getComplete()->getTimestamp() - $action->getStarted()->getTimestamp();
			$elapsed = $now->getTimestamp() - $action->getStarted()->getTimestamp();
			$done = min(1.0, $elapsed / $old_time);

			// TODO: opposing and supporting actions
			if ($action->getCharacter()->getActiveSoldiers()) {
				$attackers = $action->getCharacter()->getActiveSoldiers()->count();
			} else {
				$attackers = 0;
			}
			$additional_defenders = 0;

			foreach ($action->getSupportingActions() as $support) {
				$attackers += $support->getCharacter()->getActiveSoldiers()->count();
			}
			foreach ($action->getOpposingActions() as $oppose) {
				$additional_defenders += $oppose->getCharacter()->getActiveSoldiers()->count();
			}

			$time = $action->getTargetSettlement()->getTimeToTake($action->getCharacter(), $attackers, $additional_defenders);

			if ($time/$old_time < 0.99 || $time/$old_time > 1.01) {
				$time_left = round($time * (1-$done));
				$action->setComplete($now->add(new \DateInterval("PT".$time_left."S")));
			}
		} else {
			$this->em->remove($action);
		}
		$this->em->flush();
	}

	private function settlement_take(Action $action) {
		if ($this->check_settlement_take($action)) {
			// success
			$settlement = $action->getTargetSettlement();

			// update log access
			if ($settlement->getOwner()) {
				$this->history->closeLog($settlement, $settlement->getOwner());
			}
			$this->history->openLog($settlement, $action->getCharacter());

			// the actual change
			$this->politics->changeSettlementOwner($settlement, $action->getCharacter(), 'take');
			$this->politics->changeSettlementRealm($settlement, $action->getTargetRealm(), 'take');

			// if we are not already inside, enter
			if ($action->getCharacter()->getInsideSettlement() != $settlement) {
				$this->interactions->characterEnterSettlement($action->getCharacter(), $settlement);
			}
		}
		$this->em->remove($action);
	}

	private function settlement_loot(Action $action) {
		// just remove this, damage and all has already been applied, we just needed the action to stop travel
		$this->em->remove($action);
	}

	private function update_military_block(Action $action) {
		if ($action->getCharacter()->isInBattle()) {
			return; // to avoid double battls
		}
		// check if there are targets nearby we want to engage
		$maxdistance = 2 * $this->geography->calculateInteractionDistance($action->getCharacter());
		$possible_targets = $this->geography->findCharactersNearMe($action->getCharacter(), $maxdistance, $action->getCharacter()->getInsideSettlement()?false:true, true, false, true);

		$victims = array();
		foreach ($possible_targets as $target) {
			list($check, $list, $level) = $this->permissions->checkListing($action->getTargetListing(), $target['character']);
			if ( ($check && $action->getStringValue()=='attack') || (!$check && $action->getStringValue()=='allow') ) {
				$victims[] = $target['character'];
			}
		}
		if ($victims) {
			$this->createBattle($action->getCharacter(), null, $victims);
			$this->em->remove($action);
		}
	}


	private function military_damage(Action $action) {
		// just remove this, damage and all has already been applied, we just needed the action to stop travel
		$this->em->remove($action);
	}

	private function military_hire(Action $action) {
		// just remove this, it is just a timed action to stop rich people from hiring all mercenaries in one go
		$this->em->remove($action);
	}

	private function military_regroup(Action $action) {
		// just remove this, it is just a timed action to stop immediate re-engagements
		$this->history->logEvent(
			$action->getCharacter(),
			'resolution.regroup.success',
			array(),
			History::LOW, false, 15
		);
		$this->em->remove($action);
	}

	// TODO: this is not actually being used anymore - do we still want to keep it?
	private function settlement_enter(Action $action) {
		$settlement = $action->getTargetSettlement();

		if (!$settlement) {
			$this->log(0, 'invalid action '.$action->getId());
			// TODO: clean it up, but during alpha we want it to hang around for debug purposes
			return;
		}

		if ($this->interactions->characterEnterSettlement($action->getCharacter(), $settlement)) {
			// entered the place
			$this->history->logEvent(
				$action->getCharacter(),
				'resolution.enter.success',
				array('%settlement%'=>$settlement),
				History::LOW, false, 20
			);
		} else {
			// we are not allowed to enter
			$this->history->logEvent(
				$action->getCharacter(),
				'resolution.enter.success',
				array('%settlement%'=>$settlement),
				History::LOW, false, 20
			);
		}
		$this->em->remove($action);
	}

	private function settlement_rename(Action $action) {
		$settlement = $action->getTargetSettlement();
		$newname = $action->getStringValue();
		$oldname = $settlement->getName();
		if (!$settlement || !$newname || $newname=="") {
			$this->log(0, 'invalid action '.$action->getId());
			// TODO: clean it up, but during alpha we want it to hang around for debug purposes
			return;
		}

		$test = $this->dispatcher->controlRenameTest();
		if (!isset($test['url'])) {
			$this->history->logEvent(
				$action->getCharacter(),
				'multi',
				array('events'=>array('resolution.rename.failed', 'resolution.'.$test['description']),
				 '%link-settlement%'=>$settlement->getId(), '%new%'=>$newname),
				History::LOW, false, 30
			);
		} else {
			$settlement->setName($newname);
			if ($marker = $settlement->getGeoMarker()) { $marker->setName($newname); } // update hidden geofeature
			$this->history->logEvent(
				$settlement,
				'event.settlement.renamed',
				array('%oldname%'=>$oldname, '%newname%'=>$newname),
				History::MEDIUM, true
			);
			$this->history->logEvent(
				$action->getCharacter(),
				'resolution.rename.success',
				array('%new%'=>$newname),
				History::LOW, false, 20
			);

		}
		$this->em->remove($action);
	}

	private function settlement_grant(Action $action) {
		$settlement = $action->getTargetSettlement();
		$to = $action->getTargetCharacter();

		if (!$settlement || !$to || !$action->getCharacter()) {
			$this->log(0, 'invalid action '.$action->getId());
			// TODO: clean it up, but during alpha we want it to hang around for debug purposes
			return;
		}


		$test = $this->dispatcher->controlGrantTest();
		if (!isset($test['url'])) {
			$this->history->logEvent(
				$action->getCharacter(),
				'resolution.grant.failed',
				array('%link-settlement%'=>$settlement->getId(), '%link-character%'=>$to->getId(), '%reason%'=>array('key'=>'resolution.'.$test['description'])),
				History::MEDIUM, false, 30
			);
		} else {
			if ($settlement->getOwner()) {
				$this->history->closeLog($settlement, $settlement->getOwner());
			}
			$this->history->openLog($settlement, $to);
			if (strpos($action->getStringValue(), 'keep_claim') === false) {
				$reason = 'grant';
			} else {
				$reason = 'grant_fief';
			}
			$this->politics->changeSettlementOwner($settlement, $to, 'grant');

			if (strpos($action->getStringValue(), 'clear_realm') !== false && $settlement->getRealm()) {
				$this->politics->changeSettlementRealm($settlement, null, 'grant');
			}
		}
		$this->em->remove($action);
	}



	private function settlement_attack(Action $action) {
		// this is just a convenience alias
		$this->military_battle($action);
	}

	private function update_settlement_defend(Action $action) {
		if (!$action->getCharacter() || !$action->getTargetSettlement()) {
			$this->log(0, 'invalid action '.$action->getId());
			// TODO: clean it up, but during alpha we want it to hang around for debug purposes
			return;
		}

		// check if we are in action range
		$distance = $this->geography->calculateDistanceToSettlement($action->getCharacter(), $action->getTargetSettlement());
		$actiondistance = $this->geography->calculateActionDistance($action->getTargetSettlement());
		if ($distance > $actiondistance) {
			$this->history->logEvent(
				$action->getCharacter(),
				'resolution.defend.removed',
				array('%link-settlement%'=>$action->getTargetSettlement()->getId()),
				History::LOW, false, 10
			);
			$this->em->remove($action);
		}
	}

	private function update_military_aid(Action $action) {
		$character = $action->getCharacter();
		if ($character->isInBattle() || $character->isDoingAction('military.regroup')) {
			return;
		}

		// check if target is in battle and within interaction range
		if ($action->getTargetCharacter()->isInBattle()) {
			$distance = $this->geography->calculateDistanceToCharacter($character, $action->getTargetCharacter());
			$actiondistance = $this->geography->calculateInteractionDistance($character);
			if ($distance < $actiondistance && $character->getInsideSettlement() == $action->getTargetCharacter()->getInsideSettlement()) {
				// join all battles on his side
				foreach ($action->getTargetCharacter()->getBattlegroups() as $group) {
					$this->military->joinBattle($character, $group);
					$this->history->logEvent(
						$character,
						'resolution.aid.success',
						array('%link-character%'=>$action->getTargetCharacter()->getId()),
						History::HIGH, false, 15
					);
				}

				$this->em->remove($action);
			}
		}
	}

	private function military_aid(Action $action) {
		// support ends
		$this->history->logEvent(
			$action->getCharacter(),
			'resolution.aid.removed',
			array('%link-character%'=>$action->getTargetCharacter()->getId()),
			History::LOW, false, 10
		);
		$this->em->remove($action);
	}

	private function military_battle(Action $action) {
		// battlerunner actually resolves this all
	}

	private function military_disengage(Action $action) {
		$char = $action->getCharacter();

		// ideas:
		// * chance should depend on relative force sizes and maybe scouts and other entourage
		// * larger enemy forces can encircle better
		// * small parts of large forces can evade better (betrayl works....)

		// TODO: how to notify parties remaining in the battle? Do we need a battle event log? hope not!

		// higher chance to evade if we are in multiple battles?

		$chance = 40;
		// the larger my army, the less chance I have to evade (with 500 people, -50 %)
		$chance -= sqrt( ($char->getSoldiers()->count() + $char->getEntourage()->count()) * 5);

		// biome - we re-use spotting here
		$biome = $this->geography->getLocalBiome($char);
		$chance *= 1/$biome->getSpot();

		// avoid the abusive "catch with small army to engage, while large army moves in for the kill" abuse for extreme scenarios
		$enemies = $action->getTargetBattlegroup()->getEnemy()->getActiveSoldiers()->count();
		if ($enemies < 5) {
			$chance += 30;
		} elseif ($enemies < 10) {
			$chance += 20;
		} elseif ($enemies < 25) {
			$chance += 10;
		}

		// cap between 5% and 80%
		$chance = min(80, max(5,$chance));

		if ($char->isDoingAction('military.block')
			|| $char->isDoingAction('military.damage')
			|| $char->isDoingAction('military.loot')
			|| $char->isDoingAction('settlement.attack')
			|| $char->isDoingAction('settlement.defend') ) {
			// these actions are incompatible with evasion - fail
			$chance = 0;
		}

		if (rand(0,100) < $chance) {
			// add a short regroup timer to those who engaged me, to prevent immediate re-engages
			foreach ($action->getTargetBattlegroup()->getEnemy()->getCharacters() as $enemy) {
				$act = new Action;
				$act->setType('military.regroup')->setCharacter($enemy);
				$act->setBlockTravel(false);
				$act->setCanCancel(false);
				$complete = new \DateTime('now');
				$complete->add(new \DateInterval('PT60M'));
				$act->setComplete($complete);
				$this->queue($act, true);
			}
			$this->military->removeCharacterFromBattlegroup($char, $action->getTargetBattlegroup());
			$this->history->logEvent(
				$char,
				'resolution.disengage.success',
				array(),
				History::MEDIUM, false, 10
			);
			$this->em->remove($action);

			$get_away = 0.1;
		} else {
			$this->history->logEvent(
				$char,
				'resolution.disengage.failed',
				array(),
				History::MEDIUM, false, 10
			);
			$action->setType('military.intercepted');
			$action->setCanCancel(false);
			$action->setHidden(true);

			$get_away = 0.05;
		}

		// find the battle action and make it not blocking travel
		foreach ($char->getActions() as $sub_action) {
			if ($sub_action->getType()=='military.battle' && $sub_action->getTargetBattlegroup() == $action->getTargetBattlegroup()) {
				$sub_action->setBlockTravel(false);
			}
		}

		// to avoid people being trapped by overlapping engages - allow them to move a tiny bit along travel route
		// 0.1 is 10% of a day's journey, or about 50% of an hourly journey - or about 1km base speed, modified for character speed
		// if the disengage failed, we move half that.
		if ($char->getTravel()) {
			$char->setProgress(min(1.0, $char->getProgress() + $char->getSpeed()*$get_away));
		} else {
			// TODO: we should move a tiny bit, but must take rivers, oceans, etc. into account - can we re-use the travel check somehow?
		}
	}


	private function personal_prisonassign(Action $action) {
		// just remove, this is just a blocking action
		$this->em->remove($action);
	}

	private function character_escape(Action $action) {
		// just remove, this is just a blocking action
		$char = $action->getCharacter();

		if ($captor = $char->getPrisonerOf()) {
			// low chance if captor is active, otherwise automatic
			if ($captor->isActive()) {
				$chance = 10;
			} else {
				$chance = 100;
			}
			if (rand(0,99) < $chance) {
				// escaped!
				$captor->removePrisoner($char);
				$char->setPrisonerOf(null);
				$this->history->logEvent(
					$char,
					'resolution.escape.success',
					array(),
					History::HIGH, true, 20
				);
				$this->history->logEvent(
					$captor,
					'resolution.escape.by',
					array('%link-character%'=>$char->getId()),
					History::MEDIUM, false, 30
				);
			} else {
				// failed
				$this->history->logEvent(
					$char,
					'resolution.escape.failed',
					array(),
					History::HIGH, true, 20
				);
				$this->history->logEvent(
					$captor,
					'resolution.escape.try',
					array('%link-character%'=>$char->getId()),
					History::MEDIUM, false, 30
				);
			}
		}

		$this->em->remove($action);
	}

	private function task_research(Action $action) {
		// TODO: shift event journal start max(one day, one task) into the past
		// easily done: get cycle of next-oldest date and shift to there

		if ($action->getTargetRealm()) {
			$log = $action->getTargetRealm()->getLog();
		} elseif ($action->getTargetSettlement()) {
			$log = $action->getTargetSettlement()->getLog();
		} elseif ($action->getTargetCharacter()) {
			$log = $action->getTargetCharacter()->getLog();
		} else {
			// FIXME: should never happen
			$this->log(0, 'invalid research action '.$action->getId());
			// TODO: clean it up, but during alpha we want it to hang around for debug purposes
			return;
		}
		$meta = $this->em->getRepository('BM2SiteBundle:EventMetadata')->findOneBy(array('log'=>$log, 'reader'=>$action->getCharacter()));

		$query = $this->em->createQuery('SELECT MAX(e.cycle) FROM BM2SiteBundle:Event e WHERE e.log=:log AND e.cycle < :earliest');
		$query->setParameters(array('log'=>$log, 'earliest'=>$meta->getAccessFrom()));
		$next = $query->getSingleScalarResult();
		$meta->setAccessFrom($next);

		// TODO: merging of meta data when we have multiple periods of access
		// see history::investigateLog() - actually, we might move this code here to there

		if (!$next) {
			foreach ($action->getAssignedEntourage() as $npc) {
				$npc->setAction(null);
			}
			$this->history->logEvent(
				$action->getCharacter(),
				'resolution.research.complete', array("%link-log%"=>$log->getId()),
				History::LOW, false, 30
			);
			$this->em->remove($action);
		}
	}

	public function log($level, $text) {
		if ($level <= $this->debug) {
			echo $text."\n";
			flush();			
		}
	}



	public function createBattle(Character $character, Settlement $settlement=null, $targets=array()) {
		if ($settlement) {
			$location = $settlement->getGeoData()->getCenter();
			$outside = false;
		} else {
			$x=0; $y=0; $count=0; $outside = false;
			foreach ($targets as $target) {
				$x+=$target->getLocation()->getX();
				$y+=$target->getLocation()->getY();
				$count++;
				if (!$target->getInsideSettlement()) {
					$outside = true;
				}
			}
			$location = new Point($x/$count, $y/$count);
		}
		$battle = new Battle;
		$battle->setLocation($location);
		if ($settlement) {
			// FIXME: this should also be set (but differently) if everyone involved is inside the settlement
			$battle->setSettlement($settlement);
			$this->history->logEvent(
				$settlement,
				'event.settlement.attacked',
				array('%link-character%'=>$character->getId()),
				History::MEDIUM, false, 60
			);
		}
		$battle->setSiege(false); // TODO...
		$battle->setStarted(new \DateTime('now'));

		// setup attacker (i.e. me)
		$attackers = new BattleGroup;
		$attackers->setBattle($battle);
		$attackers->setAttacker(true);
		$battle->addGroup($attackers);

		// setup defenders
		$defenders = new BattleGroup;
		$defenders->setBattle($battle);
		$defenders->setAttacker(false);
		$battle->addGroup($defenders);

		// now we have all involved set up we can calculate the preparation timer
		$time = $this->military->calculatePreparationTime($battle);
		$complete = new \DateTime('now');
		$complete->add(new \DateInterval('PT'.$time.'S'));
		$battle->setInitialComplete($complete)->setComplete($complete);

		// setup battle communications
		$group = $this->communication->CreateMessageGroup($battle->getName(), false, null, null, $battle);
		if ($settlement && $settlement->getOwner()) {
			$this->communication->joinGroup($settlement->getOwner(), $group, false);
		}

		// add characters - this also sets up actions and locks travel
		$this->joinBattle($character, $attackers, false);
		foreach ($targets as $target) {
			$this->joinBattle($target, $defenders, false, true);
		}

		$this->em->persist($battle);
		$this->em->persist($attackers);
		$this->em->persist($defenders);

		// notifications and counter-actions
		foreach ($targets as $target) {
			if ($target->hasAction('military.evade')) {
				// we have an evade action set, so automatically queue a disengage
				$this->createDisengage($target, $defenders, $act);
				// and notify
				$this->history->logEvent(
					$target,
					'resolution.attack.evading', array("%time%"=>$this->gametime->realtimeFilter($time)),
					History::HIGH, false, 25
				);
			} else {
				// regular notififaction
				$this->history->logEvent(
					$target,
					'resolution.attack.targeted', array("%time%"=>$this->gametime->realtimeFilter($time)),
					History::HIGH, false, 25
				);
			}

			$target->setTravelLocked(true);
		}

		if ($settlement) {
			// add everyone who has a "defend settlement" action set
			foreach ($settlement->getRelatedActions() as $defender) {
				if ($defender->getType()=='settlement.defend') {
					$this->joinBattle($defender->getCharacter(), $defenders, false, true);

					// notify
					$this->history->logEvent(
						$defender->getCharacter(),
						'resolution.defend.success', array(
							"%link-settlement%"=>$settlement->getId(),
							"%time%"=>$this->gametime->realtimeFilter($time)
						),
						History::HIGH, false, 25
					);
				}
			}
		}

		return array('time'=>$time, 'outside'=>$outside, 'battle'=>$battle);
	}



	public function calculateDisengageTime(Character $character) {
		$base = 15;
		$base += sqrt($character->getEntourage()->count()*10);

		$takes = $character->getSoldiers()->count() * 5;
		foreach ($character->getSoldiers() as $soldier) {
			if ($soldier->isWounded()) {
				$takes += 5;
			}
			switch ($soldier->getType()) {
				case 'cavalry':
				case 'mounted archer':		$takes += 3;
				case 'heavy infantry':		$takes += 2;
			}
		}

		$base += sqrt($takes);

		return $base*60;
	}

	public function createDisengage(Character $character, BattleGroup $bg, Action $attack) {
		$takes = $this->calculateDisengageTime($character);
		$complete = new \DateTime("now");
		$complete->add(new \DateInterval("PT".round($takes)."S"));
		// TODO: at most until just before the battle!

		$act = new Action;
		$act->setType('military.disengage')
			->setCharacter($character)
			->setTargetBattlegroup($bg)
			->setCanCancel(true)
			->setOpposedAction($attack)
			->setComplete($complete)
			->setBlockTravel(false);
		$act->addOpposingAction($act);

		return $this->queue($act);
	}

}
