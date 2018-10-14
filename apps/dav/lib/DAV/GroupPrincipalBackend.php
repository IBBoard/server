<?php
/**
 * @copyright Copyright (c) 2016, ownCloud, Inc.
 *
 * @author Roeland Jago Douma <roeland@famdouma.nl>
 * @author Thomas Müller <thomas.mueller@tmit.eu>
 *
 * @license AGPL-3.0
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License, version 3,
 * along with this program.  If not, see <http://www.gnu.org/licenses/>
 *
 */
namespace OCA\DAV\DAV;

use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUserSession;
use OCP\Share\IManager as IShareManager;
use OCP\IL10N;
use OCP\IUser;
use Sabre\DAV\Exception;
use \Sabre\DAV\PropPatch;
use Sabre\DAVACL\PrincipalBackend\BackendInterface;

class GroupPrincipalBackend implements BackendInterface {

	const PRINCIPAL_PREFIX = 'principals/groups';

	/** @var IGroupManager */
	private $groupManager;

	/** @var IUserSession */
	private $userSession;

	/** @var IShareManager */
	private $shareManager;

	/** @var IL10N */
	private $l10n;

	/**
	 * @param IGroupManager $IGroupManager
	 * @param IUserSession $userSession
	 * @param IShareManager $shareManager
	 * @param IL10N $l10n
	 */
	public function __construct(IGroupManager $IGroupManager,
								IUserSession $userSession,
								IShareManager $shareManager,
								IL10N $l10n) {
		$this->groupManager = $IGroupManager;
		$this->userSession = $userSession;
		$this->shareManager = $shareManager;
		$this->l10n = $l10n;
	}

	/**
	 * Returns a list of principals based on a prefix.
	 *
	 * This prefix will often contain something like 'principals'. You are only
	 * expected to return principals that are in this base path.
	 *
	 * You are expected to return at least a 'uri' for every user, you can
	 * return any additional properties if you wish so. Common properties are:
	 *   {DAV:}displayname
	 *
	 * @param string $prefixPath
	 * @return string[]
	 */
	public function getPrincipalsByPrefix($prefixPath) {
		$principals = [];

		if ($prefixPath === self::PRINCIPAL_PREFIX) {
			foreach($this->groupManager->search('') as $user) {
				$principals[] = $this->groupToPrincipal($user);
			}
		}

		return $principals;
	}

	/**
	 * Returns a specific principal, specified by it's path.
	 * The returned structure should be the exact same as from
	 * getPrincipalsByPrefix.
	 *
	 * @param string $path
	 * @return array
	 */
	public function getPrincipalByPath($path) {
		$elements = explode('/', $path,  3);
		if ($elements[0] !== 'principals') {
			return null;
		}
		if ($elements[1] !== 'groups') {
			return null;
		}
		$name = urldecode($elements[2]);
		$group = $this->groupManager->get($name);

		if (!is_null($group)) {
			return $this->groupToPrincipal($group);
		}

		return null;
	}

	/**
	 * Returns the list of members for a group-principal
	 *
	 * @param string $principal
	 * @return string[]
	 * @throws Exception
	 */
	public function getGroupMemberSet($principal) {
		$elements = explode('/', $principal);
		if ($elements[0] !== 'principals') {
			return [];
		}
		if ($elements[1] !== 'groups') {
			return [];
		}
		$name = $elements[2];
		$group = $this->groupManager->get($name);

		if (is_null($group)) {
			return [];
		}

		return array_map(function($user) {
			return $this->userToPrincipal($user);
		}, $group->getUsers());
	}

	/**
	 * Returns the list of groups a principal is a member of
	 *
	 * @param string $principal
	 * @return array
	 * @throws Exception
	 */
	public function getGroupMembership($principal) {
		return [];
	}

	/**
	 * Updates the list of group members for a group principal.
	 *
	 * The principals should be passed as a list of uri's.
	 *
	 * @param string $principal
	 * @param string[] $members
	 * @throws Exception
	 */
	public function setGroupMemberSet($principal, array $members) {
		throw new Exception('Setting members of the group is not supported yet');
	}

	/**
	 * @param string $path
	 * @param PropPatch $propPatch
	 * @return int
	 */
	function updatePrincipal($path, PropPatch $propPatch) {
		return 0;
	}

	/**
	 * @param string $prefixPath
	 * @param array $searchProperties
	 * @param string $test
	 * @return array
	 */
	function searchPrincipals($prefixPath, array $searchProperties, $test = 'allof') {
		if (\count($searchProperties) === 0) {
			return [];
		}
		if ($prefixPath !== self::PRINCIPAL_PREFIX) {
			return [];
		}

		// If sharing is restricted to group members only,
		// return only members that have groups in common
		$restrictGroups = false;
		if ($this->shareManager->shareWithGroupMembersOnly()) {
			$user = $this->userSession->getUser();
			if (!$user) {
				return [];
			}

			$restrictGroups = $this->groupManager->getUserGroupIds($user);
		}

		foreach ($searchProperties as $prop => $value) {
			switch ($prop) {
				case '{DAV:}displayname':
					$users = $this->groupManager->search($value);

					$results[] = array_reduce($users, function(array $carry, IGroup $group) use ($restrictGroups) {
						// is sharing restricted to groups only?
						if ($restrictGroups !== false) {
							if (!\in_array($group->getGID(), $restrictGroups, true)) {
								return $carry;
							}
						}

						$carry[] = self::PRINCIPAL_PREFIX . '/' . $group->getGID();
						return $carry;
					}, []);
					break;

				default:
					$results[] = [];
					break;
			}
		}

		// results is an array of arrays, so this is not the first search result
		// but the results of the first searchProperty
		if (count($results) === 1) {
			return $results[0];
		}

		switch ($test) {
			case 'anyof':
				return array_unique(array_merge(...$results));

			case 'allof':
			default:
				return array_intersect(...$results);
		}
	}

	/**
	 * @param string $uri
	 * @param string $principalPrefix
	 * @return string
	 */
	function findByUri($uri, $principalPrefix) {
		if (substr($uri, 0, 10) === 'principal:') {
			$principal = substr($uri, 10);
			$principal = $this->getPrincipalByPath($principal);
			if ($principal !== null) {
				return $principal['uri'];
			}
		}

		return null;
	}

	/**
	 * @param IGroup $group
	 * @return array
	 */
	protected function groupToPrincipal($group) {
		$groupId = $group->getGID();

		return [
			'uri' => 'principals/groups/' . urlencode($groupId),
			'{DAV:}displayname' => $this->l10n->t('%s (group)', [$groupId]),
			'{urn:ietf:params:xml:ns:caldav}calendar-user-type' => 'GROUP',
		];
	}

	/**
	 * @param IUser $user
	 * @return array
	 */
	protected function userToPrincipal($user) {
		$userId = $user->getUID();
		$displayName = $user->getDisplayName();

		$principal = [
			'uri' => 'principals/users/' . $userId,
			'{DAV:}displayname' => is_null($displayName) ? $userId : $displayName,
			'{urn:ietf:params:xml:ns:caldav}calendar-user-type' => 'INDIVIDUAL',
		];

		$email = $user->getEMailAddress();
		if (!empty($email)) {
			$principal['{http://sabredav.org/ns}email-address'] = $email;
		}

		return $principal;
	}
}
