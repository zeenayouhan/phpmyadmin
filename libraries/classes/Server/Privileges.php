<?php
/**
 * set of functions with the Privileges section in pma
 *
 * @package PhpMyAdmin
 */
declare(strict_types=1);

namespace PhpMyAdmin\Server;

use PhpMyAdmin\Core;
use PhpMyAdmin\DatabaseInterface;
use PhpMyAdmin\Display\ChangePassword;
use PhpMyAdmin\Message;
use PhpMyAdmin\Relation;
use PhpMyAdmin\RelationCleanup;
use PhpMyAdmin\Response;
use PhpMyAdmin\Template;
use PhpMyAdmin\Url;
use PhpMyAdmin\Util;

/**
 * Privileges class
 *
 * @package PhpMyAdmin
 */
class Privileges
{
    /**
     * @var Template
     */
    public $template;

    /**
     * @var RelationCleanup
     */
    private $relationCleanup;

    /**
     * @var DatabaseInterface
     */
    public $dbi;

    /**
     * @var Relation
     */
    public $relation;

    /**
     * Privileges constructor.
     *
     * @param Template          $template        Template object
     * @param DatabaseInterface $dbi             DatabaseInterface object
     * @param Relation          $relation        Relation object
     * @param RelationCleanup   $relationCleanup RelationCleanup object
     */
    public function __construct(
        Template $template,
        $dbi,
        Relation $relation,
        RelationCleanup $relationCleanup
    ) {
        $this->template = $template;
        $this->dbi = $dbi;
        $this->relation = $relation;
        $this->relationCleanup = $relationCleanup;
    }

    /**
     * Get Html for User Group Dialog
     *
     * @param string $username     username
     * @param bool   $is_menuswork Is menuswork set in configuration
     *
     * @return string html
     */
    public function getHtmlForUserGroupDialog($username, $is_menuswork)
    {
        $html = '';
        if (! empty($_GET['edit_user_group_dialog']) && $is_menuswork) {
            $dialog = $this->getHtmlToChooseUserGroup($username);
            $response = Response::getInstance();
            if ($response->isAjax()) {
                $response->addJSON('message', $dialog);
                exit;
            } else {
                $html .= $dialog;
            }
        }

        return $html;
    }

    /**
     * Escapes wildcard in a database+table specification
     * before using it in a GRANT statement.
     *
     * Escaping a wildcard character in a GRANT is only accepted at the global
     * or database level, not at table level; this is why I remove
     * the escaping character. Internally, in mysql.tables_priv.Db there are
     * no escaping (for example test_db) but in mysql.db you'll see test\_db
     * for a db-specific privilege.
     *
     * @param string $dbname    Database name
     * @param string $tablename Table name
     *
     * @return string the escaped (if necessary) database.table
     */
    public function wildcardEscapeForGrant($dbname, $tablename)
    {
        if (strlen($dbname) === 0) {
            $db_and_table = '*.*';
        } else {
            if (strlen($tablename) > 0) {
                $db_and_table = Util::backquote(
                    Util::unescapeMysqlWildcards($dbname)
                )
                . '.' . Util::backquote($tablename);
            } else {
                $db_and_table = Util::backquote($dbname) . '.*';
            }
        }
        return $db_and_table;
    }

    /**
     * Generates a condition on the user name
     *
     * @param string $initial the user's initial
     *
     * @return string   the generated condition
     */
    public function rangeOfUsers($initial = '')
    {
        // strtolower() is used because the User field
        // might be BINARY, so LIKE would be case sensitive
        if ($initial === null || $initial === '') {
            return '';
        }

        $ret = " WHERE `User` LIKE '"
            . $this->dbi->escapeString($initial) . "%'"
            . " OR `User` LIKE '"
            . $this->dbi->escapeString(mb_strtolower($initial))
            . "%'";
        return $ret;
    }

    /**
     * Parses privileges into an array, it modifies the array
     *
     * @param array $row Results row from
     *
     * @return void
     */
    public function fillInTablePrivileges(array &$row)
    {
        $row1 = $this->dbi->fetchSingleRow(
            'SHOW COLUMNS FROM `mysql`.`tables_priv` LIKE \'Table_priv\';',
            'ASSOC'
        );
        // note: in MySQL 5.0.3 we get "Create View', 'Show view';
        // the View for Create is spelled with uppercase V
        // the view for Show is spelled with lowercase v
        // and there is a space between the words

        $av_grants = explode(
            '\',\'',
            mb_substr(
                $row1['Type'],
                mb_strpos($row1['Type'], '(') + 2,
                mb_strpos($row1['Type'], ')')
                - mb_strpos($row1['Type'], '(') - 3
            )
        );

        $users_grants = explode(',', $row['Table_priv']);

        foreach ($av_grants as $current_grant) {
            $row[$current_grant . '_priv']
                = in_array($current_grant, $users_grants) ? 'Y' : 'N';
        }
        unset($row['Table_priv']);
    }


    /**
     * Extracts the privilege information of a priv table row
     *
     * @param array|null $row        the row
     * @param boolean    $enableHTML add <dfn> tag with tooltips
     * @param boolean    $tablePrivs whether row contains table privileges
     *
     * @global  resource $user_link the database connection
     *
     * @return array
     */
    public function extractPrivInfo($row = null, $enableHTML = false, $tablePrivs = false)
    {
        if ($tablePrivs) {
            $grants = $this->getTableGrantsArray();
        } else {
            $grants = $this->getGrantsArray();
        }

        if ($row !== null && isset($row['Table_priv'])) {
            $this->fillInTablePrivileges($row);
        }

        $privs = [];
        $allPrivileges = true;
        foreach ($grants as $current_grant) {
            if (($row !== null && isset($row[$current_grant[0]]))
                || ($row === null && isset($GLOBALS[$current_grant[0]]))
            ) {
                if (($row !== null && $row[$current_grant[0]] == 'Y')
                    || ($row === null
                    && ($GLOBALS[$current_grant[0]] == 'Y'
                    || (is_array($GLOBALS[$current_grant[0]])
                    && count($GLOBALS[$current_grant[0]]) == $_REQUEST['column_count']
                    && empty($GLOBALS[$current_grant[0] . '_none']))))
                ) {
                    if ($enableHTML) {
                        $privs[] = '<dfn title="' . $current_grant[2] . '">'
                        . $current_grant[1] . '</dfn>';
                    } else {
                        $privs[] = $current_grant[1];
                    }
                } elseif (! empty($GLOBALS[$current_grant[0]])
                    && is_array($GLOBALS[$current_grant[0]])
                    && empty($GLOBALS[$current_grant[0] . '_none'])
                ) {
                    // Required for proper escaping of ` (backtick) in a column name
                    $grant_cols = array_map(
                        function ($val) {
                            return Util::backquote($val);
                        },
                        $GLOBALS[$current_grant[0]]
                    );

                    if ($enableHTML) {
                        $privs[] = '<dfn title="' . $current_grant[2] . '">'
                            . $current_grant[1] . '</dfn>'
                            . ' (' . implode(', ', $grant_cols) . ')';
                    } else {
                        $privs[] = $current_grant[1]
                            . ' (' . implode(', ', $grant_cols) . ')';
                    }
                } else {
                    $allPrivileges = false;
                }
            }
        }
        if (empty($privs)) {
            if ($enableHTML) {
                $privs[] = '<dfn title="' . __('No privileges.') . '">USAGE</dfn>';
            } else {
                $privs[] = 'USAGE';
            }
        } elseif ($allPrivileges
            && (! isset($_POST['grant_count']) || count($privs) == $_POST['grant_count'])
        ) {
            if ($enableHTML) {
                $privs = ['<dfn title="'
                    . __('Includes all privileges except GRANT.')
                    . '">ALL PRIVILEGES</dfn>',
                ];
            } else {
                $privs = ['ALL PRIVILEGES'];
            }
        }
        return $privs;
    }

    /**
     * Returns an array of table grants and their descriptions
     *
     * @return array array of table grants
     */
    public function getTableGrantsArray()
    {
        return [
            [
                'Delete',
                'DELETE',
                $GLOBALS['strPrivDescDelete'],
            ],
            [
                'Create',
                'CREATE',
                $GLOBALS['strPrivDescCreateTbl'],
            ],
            [
                'Drop',
                'DROP',
                $GLOBALS['strPrivDescDropTbl'],
            ],
            [
                'Index',
                'INDEX',
                $GLOBALS['strPrivDescIndex'],
            ],
            [
                'Alter',
                'ALTER',
                $GLOBALS['strPrivDescAlter'],
            ],
            [
                'Create View',
                'CREATE_VIEW',
                $GLOBALS['strPrivDescCreateView'],
            ],
            [
                'Show view',
                'SHOW_VIEW',
                $GLOBALS['strPrivDescShowView'],
            ],
            [
                'Trigger',
                'TRIGGER',
                $GLOBALS['strPrivDescTrigger'],
            ],
        ];
    }

    /**
     * Get the grants array which contains all the privilege types
     * and relevant grant messages
     *
     * @return array
     */
    public function getGrantsArray()
    {
        return [
            [
                'Select_priv',
                'SELECT',
                __('Allows reading data.'),
            ],
            [
                'Insert_priv',
                'INSERT',
                __('Allows inserting and replacing data.'),
            ],
            [
                'Update_priv',
                'UPDATE',
                __('Allows changing data.'),
            ],
            [
                'Delete_priv',
                'DELETE',
                __('Allows deleting data.'),
            ],
            [
                'Create_priv',
                'CREATE',
                __('Allows creating new databases and tables.'),
            ],
            [
                'Drop_priv',
                'DROP',
                __('Allows dropping databases and tables.'),
            ],
            [
                'Reload_priv',
                'RELOAD',
                __('Allows reloading server settings and flushing the server\'s caches.'),
            ],
            [
                'Shutdown_priv',
                'SHUTDOWN',
                __('Allows shutting down the server.'),
            ],
            [
                'Process_priv',
                'PROCESS',
                __('Allows viewing processes of all users.'),
            ],
            [
                'File_priv',
                'FILE',
                __('Allows importing data from and exporting data into files.'),
            ],
            [
                'References_priv',
                'REFERENCES',
                __('Has no effect in this MySQL version.'),
            ],
            [
                'Index_priv',
                'INDEX',
                __('Allows creating and dropping indexes.'),
            ],
            [
                'Alter_priv',
                'ALTER',
                __('Allows altering the structure of existing tables.'),
            ],
            [
                'Show_db_priv',
                'SHOW DATABASES',
                __('Gives access to the complete list of databases.'),
            ],
            [
                'Super_priv',
                'SUPER',
                __(
                    'Allows connecting, even if maximum number of connections '
                    . 'is reached; required for most administrative operations '
                    . 'like setting global variables or killing threads of other users.'
                ),
            ],
            [
                'Create_tmp_table_priv',
                'CREATE TEMPORARY TABLES',
                __('Allows creating temporary tables.'),
            ],
            [
                'Lock_tables_priv',
                'LOCK TABLES',
                __('Allows locking tables for the current thread.'),
            ],
            [
                'Repl_slave_priv',
                'REPLICATION SLAVE',
                __('Needed for the replication slaves.'),
            ],
            [
                'Repl_client_priv',
                'REPLICATION CLIENT',
                __('Allows the user to ask where the slaves / masters are.'),
            ],
            [
                'Create_view_priv',
                'CREATE VIEW',
                __('Allows creating new views.'),
            ],
            [
                'Event_priv',
                'EVENT',
                __('Allows to set up events for the event scheduler.'),
            ],
            [
                'Trigger_priv',
                'TRIGGER',
                __('Allows creating and dropping triggers.'),
            ],
            // for table privs:
            [
                'Create View_priv',
                'CREATE VIEW',
                __('Allows creating new views.'),
            ],
            [
                'Show_view_priv',
                'SHOW VIEW',
                __('Allows performing SHOW CREATE VIEW queries.'),
            ],
            // for table privs:
            [
                'Show view_priv',
                'SHOW VIEW',
                __('Allows performing SHOW CREATE VIEW queries.'),
            ],
            [
                'Delete_history_priv',
                'DELETE HISTORY',
                __('Allows deleting historical rows.'),
            ],
            [
                'Delete versioning rows_priv',
                'DELETE HISTORY',
                __('Allows deleting historical rows.'),
            ],
            [
                'Create_routine_priv',
                'CREATE ROUTINE',
                __('Allows creating stored routines.'),
            ],
            [
                'Alter_routine_priv',
                'ALTER ROUTINE',
                __('Allows altering and dropping stored routines.'),
            ],
            [
                'Create_user_priv',
                'CREATE USER',
                __('Allows creating, dropping and renaming user accounts.'),
            ],
            [
                'Execute_priv',
                'EXECUTE',
                __('Allows executing stored routines.'),
            ],
        ];
    }

    /**
     * Get sql query for display privileges table
     *
     * @param string $db       the database
     * @param string $table    the table
     * @param string $username username for database connection
     * @param string $hostname hostname for database connection
     *
     * @return string sql query
     */
    public function getSqlQueryForDisplayPrivTable($db, $table, $username, $hostname)
    {
        if ($db == '*') {
            return "SELECT * FROM `mysql`.`user`"
                . " WHERE `User` = '" . $this->dbi->escapeString($username) . "'"
                . " AND `Host` = '" . $this->dbi->escapeString($hostname) . "';";
        } elseif ($table == '*') {
            return "SELECT * FROM `mysql`.`db`"
                . " WHERE `User` = '" . $this->dbi->escapeString($username) . "'"
                . " AND `Host` = '" . $this->dbi->escapeString($hostname) . "'"
                . " AND '" . $this->dbi->escapeString(Util::unescapeMysqlWildcards($db)) . "'"
                . " LIKE `Db`;";
        }
        return "SELECT `Table_priv`"
            . " FROM `mysql`.`tables_priv`"
            . " WHERE `User` = '" . $this->dbi->escapeString($username) . "'"
            . " AND `Host` = '" . $this->dbi->escapeString($hostname) . "'"
            . " AND `Db` = '" . $this->dbi->escapeString(Util::unescapeMysqlWildcards($db)) . "'"
            . " AND `Table_name` = '" . $this->dbi->escapeString($table) . "';";
    }

    /**
     * Displays a dropdown to select the user group
     * with menu items configured to each of them.
     *
     * @param string $username username
     *
     * @return string html to select the user group
     */
    public function getHtmlToChooseUserGroup($username)
    {
        $cfgRelation = $this->relation->getRelationsParam();
        $groupTable = Util::backquote($cfgRelation['db'])
            . "." . Util::backquote($cfgRelation['usergroups']);
        $userTable = Util::backquote($cfgRelation['db'])
            . "." . Util::backquote($cfgRelation['users']);

        $userGroup = '';
        if (isset($GLOBALS['username'])) {
            $sql_query = "SELECT `usergroup` FROM " . $userTable
                . " WHERE `username` = '" . $this->dbi->escapeString($username) . "'";
            $userGroup = $this->dbi->fetchValue(
                $sql_query,
                0,
                0,
                DatabaseInterface::CONNECT_CONTROL
            );
        }

        $allUserGroups = ['' => ''];
        $sql_query = "SELECT DISTINCT `usergroup` FROM " . $groupTable;
        $result = $this->relation->queryAsControlUser($sql_query, false);
        if ($result) {
            while ($row = $this->dbi->fetchRow($result)) {
                $allUserGroups[$row[0]] = $row[0];
            }
        }
        $this->dbi->freeResult($result);

        return $this->template->render('server/privileges/choose_user_group', [
            'all_user_groups' => $allUserGroups,
            'user_group' => $userGroup,
            'params' => ['username' => $username],
        ]);
    }

    /**
     * Sets the user group from request values
     *
     * @param string $username  username
     * @param string $userGroup user group to set
     *
     * @return void
     */
    public function setUserGroup($username, $userGroup)
    {
        $userGroup = $userGroup === null ? '' : $userGroup;
        $cfgRelation = $this->relation->getRelationsParam();
        if (empty($cfgRelation['db']) || empty($cfgRelation['users']) || empty($cfgRelation['usergroups'])) {
            return;
        }

        $userTable = Util::backquote($cfgRelation['db'])
            . "." . Util::backquote($cfgRelation['users']);

        $sql_query = "SELECT `usergroup` FROM " . $userTable
            . " WHERE `username` = '" . $this->dbi->escapeString($username) . "'";
        $oldUserGroup = $this->dbi->fetchValue(
            $sql_query,
            0,
            0,
            DatabaseInterface::CONNECT_CONTROL
        );

        if ($oldUserGroup === false) {
            $upd_query = "INSERT INTO " . $userTable . "(`username`, `usergroup`)"
                . " VALUES ('" . $this->dbi->escapeString($username) . "', "
                . "'" . $this->dbi->escapeString($userGroup) . "')";
        } else {
            if (empty($userGroup)) {
                $upd_query = "DELETE FROM " . $userTable
                    . " WHERE `username`='" . $this->dbi->escapeString($username) . "'";
            } elseif ($oldUserGroup != $userGroup) {
                $upd_query = "UPDATE " . $userTable
                    . " SET `usergroup`='" . $this->dbi->escapeString($userGroup) . "'"
                    . " WHERE `username`='" . $this->dbi->escapeString($username) . "'";
            }
        }
        if (isset($upd_query)) {
            $this->relation->queryAsControlUser($upd_query);
        }
    }

    /**
     * Displays the privileges form table
     *
     * @param string  $db     the database
     * @param string  $table  the table
     * @param boolean $submit whether to display the submit button or not
     *
     * @global  array     $cfg         the phpMyAdmin configuration
     * @global  resource  $user_link   the database connection
     *
     * @return string html snippet
     */
    public function getHtmlToDisplayPrivilegesTable(
        $db = '*',
        $table = '*',
        $submit = true
    ) {
        $sql_query = '';

        if ($db == '*') {
            $table = '*';
        }
        $username = '';
        $hostname = '';
        if (isset($GLOBALS['username'])) {
            $username = $GLOBALS['username'];
            $hostname = $GLOBALS['hostname'];
            $sql_query = $this->getSqlQueryForDisplayPrivTable(
                $db,
                $table,
                $username,
                $hostname
            );
            $row = $this->dbi->fetchSingleRow($sql_query);
        }
        if (empty($row)) {
            if ($table == '*' && $this->dbi->isSuperuser()) {
                $row = [];
                if ($db == '*') {
                    $sql_query = 'SHOW COLUMNS FROM `mysql`.`user`;';
                } elseif ($table == '*') {
                    $sql_query = 'SHOW COLUMNS FROM `mysql`.`db`;';
                }
                $res = $this->dbi->query($sql_query);
                while ($row1 = $this->dbi->fetchRow($res)) {
                    if (mb_substr($row1[0], 0, 4) == 'max_') {
                        $row[$row1[0]] = 0;
                    } elseif (mb_substr($row1[0], 0, 5) == 'x509_'
                        || mb_substr($row1[0], 0, 4) == 'ssl_'
                    ) {
                        $row[$row1[0]] = '';
                    } else {
                        $row[$row1[0]] = 'N';
                    }
                }
                $this->dbi->freeResult($res);
            } elseif ($table == '*') {
                $row = [];
            } else {
                $row = ['Table_priv' => ''];
            }
        }
        if (isset($row['Table_priv'])) {
            $this->fillInTablePrivileges($row);

            // get columns
            $res = $this->dbi->tryQuery(
                'SHOW COLUMNS FROM '
                . Util::backquote(
                    Util::unescapeMysqlWildcards($db)
                )
                . '.' . Util::backquote($table) . ';'
            );
            $columns = [];
            if ($res) {
                while ($row1 = $this->dbi->fetchRow($res)) {
                    $columns[$row1[0]] = [
                        'Select' => false,
                        'Insert' => false,
                        'Update' => false,
                        'References' => false,
                    ];
                }
                $this->dbi->freeResult($res);
            }
        }

        if (! empty($columns)) {
            $res = $this->dbi->query(
                'SELECT `Column_name`, `Column_priv`'
                . ' FROM `mysql`.`columns_priv`'
                . ' WHERE `User`'
                . ' = \'' . $this->dbi->escapeString($username) . "'"
                . ' AND `Host`'
                . ' = \'' . $this->dbi->escapeString($hostname) . "'"
                . ' AND `Db`'
                . ' = \'' . $this->dbi->escapeString(
                    Util::unescapeMysqlWildcards($db)
                ) . "'"
                . ' AND `Table_name`'
                . ' = \'' . $this->dbi->escapeString($table) . '\';'
            );

            while ($row1 = $this->dbi->fetchRow($res)) {
                $row1[1] = explode(',', $row1[1]);
                foreach ($row1[1] as $current) {
                    $columns[$row1[0]][$current] = true;
                }
            }
            $this->dbi->freeResult($res);
        }

        return $this->template->render('server/privileges/privileges_table', [
            'is_global' => $db === '*',
            'is_database' => $table === '*',
            'row' => $row,
            'columns' => $columns ?? [],
            'has_submit' => $submit,
        ]);
    }

    /**
     * Get the HTML snippet for routine specific privileges
     *
     * @param string $username   username for database connection
     * @param string $hostname   hostname for database connection
     * @param string $db         the database
     * @param string $routine    the routine
     * @param string $url_dbname url encoded db name
     *
     * @return string
     */
    public function getHtmlForRoutineSpecificPrivileges(
        $username,
        $hostname,
        $db,
        $routine,
        $url_dbname
    ) {
        $privileges = $this->getRoutinePrivileges($username, $hostname, $db, $routine);

        return $this->template->render('server/privileges/edit_routine_privileges', [
            'username' => $username,
            'hostname' => $hostname,
            'database' => $db,
            'routine' => $routine,
            'privileges' => $privileges,
            'dbname' => $url_dbname,
            'current_user' => $this->dbi->getCurrentUser(),
        ]);
    }

    /**
     * Gets the currently active authentication plugins
     *
     * @param string $orig_auth_plugin Default Authentication plugin
     * @param string $mode             are we creating a new user or are we just
     *                                 changing  one?
     *                                 (allowed values: 'new', 'edit', 'change_pw')
     * @param string $versions         Is MySQL version newer or older than 5.5.7
     *
     * @return string
     */
    public function getHtmlForAuthPluginsDropdown(
        $orig_auth_plugin,
        $mode = 'new',
        $versions = 'new'
    ) {
        $select_id = 'select_authentication_plugin'
            . ($mode == 'change_pw' ? '_cp' : '');

        if ($versions == 'new') {
            $active_auth_plugins = $this->getActiveAuthPlugins();

            if (isset($active_auth_plugins['mysql_old_password'])) {
                unset($active_auth_plugins['mysql_old_password']);
            }
        } else {
            $active_auth_plugins = [
                'mysql_native_password' => __('Native MySQL authentication'),
            ];
        }

        $html_output = Util::getDropdown(
            'authentication_plugin',
            $active_auth_plugins,
            $orig_auth_plugin,
            $select_id
        );

        return $html_output;
    }

    /**
     * Gets the currently active authentication plugins
     *
     * @return array  array of plugin names and descriptions
     */
    public function getActiveAuthPlugins()
    {
        $get_plugins_query = "SELECT `PLUGIN_NAME`, `PLUGIN_DESCRIPTION`"
            . " FROM `information_schema`.`PLUGINS` "
            . "WHERE `PLUGIN_TYPE` = 'AUTHENTICATION';";
        $resultset = $this->dbi->query($get_plugins_query);

        $result = [];

        while ($row = $this->dbi->fetchAssoc($resultset)) {
            // if description is known, enable its translation
            if ('mysql_native_password' == $row['PLUGIN_NAME']) {
                $row['PLUGIN_DESCRIPTION'] = __('Native MySQL authentication');
            } elseif ('sha256_password' == $row['PLUGIN_NAME']) {
                $row['PLUGIN_DESCRIPTION'] = __('SHA256 password authentication');
            }

            $result[$row['PLUGIN_NAME']] = $row['PLUGIN_DESCRIPTION'];
        }

        return $result;
    }

    /**
     * Displays the fields used by the "new user" form as well as the
     * "change login information / copy user" form.
     *
     * @param string $mode are we creating a new user or are we just
     *                     changing  one? (allowed values: 'new', 'change')
     * @param string $user User name
     * @param string $host Host name
     *
     * @return string  a HTML snippet
     */
    public function getHtmlForLoginInformationFields(
        $mode = 'new',
        $user = null,
        $host = null
    ) {
        global $pred_username, $pred_hostname, $username, $hostname, $new_username;

        list($usernameLength, $hostnameLength) = $this->getUsernameAndHostnameLength();

        if (isset($username) && strlen($username) === 0) {
            $pred_username = 'any';
        }

        $currentUser = $this->dbi->fetchValue('SELECT USER();');
        $thisHost = null;
        if (! empty($currentUser)) {
            $thisHost = str_replace(
                '\'',
                '',
                mb_substr(
                    $currentUser,
                    mb_strrpos($currentUser, '@') + 1
                )
            );
        }

        if (! isset($pred_hostname) && isset($hostname)) {
            switch (mb_strtolower($hostname)) {
                case 'localhost':
                case '127.0.0.1':
                    $pred_hostname = 'localhost';
                    break;
                case '%':
                    $pred_hostname = 'any';
                    break;
                default:
                    $pred_hostname = 'userdefined';
                    break;
            }
        }

        $serverType = Util::getServerType();
        $serverVersion = $this->dbi->getVersion();
        $authPlugin = $this->getCurrentAuthenticationPlugin(
            $mode,
            $user,
            $host
        );

        if (($serverType == 'MySQL'
            && $serverVersion >= 50507)
            || ($serverType == 'MariaDB'
            && $serverVersion >= 50200)
        ) {
            $isNew = true;
            $authPluginDropdown = $this->getHtmlForAuthPluginsDropdown(
                $authPlugin,
                $mode,
                'new'
            );
        } else {
            $isNew = false;
            $authPluginDropdown = $this->getHtmlForAuthPluginsDropdown(
                $authPlugin,
                $mode,
                'old'
            );
        }

        return $this->template->render('server/privileges/login_information_fields', [
            'pred_username' => $pred_username ?? null,
            'pred_hostname' => $pred_hostname ?? null,
            'username_length' => $usernameLength,
            'hostname_length' => $hostnameLength,
            'username' => $username ?? null,
            'new_username' => $new_username ?? null,
            'hostname' => $hostname ?? null,
            'this_host' => $thisHost,
            'is_change' => $mode === 'change',
            'auth_plugin' => $authPlugin,
            'auth_plugin_dropdown' => $authPluginDropdown,
            'is_new' => $isNew,
        ]);
    }

    /**
     * Get username and hostname length
     *
     * @return array username length and hostname length
     */
    public function getUsernameAndHostnameLength()
    {
        /* Fallback values */
        $username_length = 16;
        $hostname_length = 41;

        /* Try to get real lengths from the database */
        $fields_info = $this->dbi->fetchResult(
            'SELECT COLUMN_NAME, CHARACTER_MAXIMUM_LENGTH '
            . 'FROM information_schema.columns '
            . "WHERE table_schema = 'mysql' AND table_name = 'user' "
            . "AND COLUMN_NAME IN ('User', 'Host')"
        );
        foreach ($fields_info as $val) {
            if ($val['COLUMN_NAME'] == 'User') {
                $username_length = $val['CHARACTER_MAXIMUM_LENGTH'];
            } elseif ($val['COLUMN_NAME'] == 'Host') {
                $hostname_length = $val['CHARACTER_MAXIMUM_LENGTH'];
            }
        }
        return [
            $username_length,
            $hostname_length,
        ];
    }

    /**
     * Get current authentication plugin in use - for a user or globally
     *
     * @param string $mode     are we creating a new user or are we just
     *                         changing  one? (allowed values: 'new', 'change')
     * @param string $username User name
     * @param string $hostname Host name
     *
     * @return string authentication plugin in use
     */
    public function getCurrentAuthenticationPlugin(
        $mode = 'new',
        $username = null,
        $hostname = null
    ) {
        /* Fallback (standard) value */
        $authentication_plugin = 'mysql_native_password';
        $serverVersion = $this->dbi->getVersion();

        if (isset($username, $hostname) && $mode == 'change') {
            $row = $this->dbi->fetchSingleRow(
                'SELECT `plugin` FROM `mysql`.`user` WHERE '
                . '`User` = "' . $username . '" AND `Host` = "' . $hostname . '" LIMIT 1'
            );
            // Table 'mysql'.'user' may not exist for some previous
            // versions of MySQL - in that case consider fallback value
            if (is_array($row) && isset($row['plugin'])) {
                $authentication_plugin = $row['plugin'];
            }
        } elseif ($mode == 'change') {
            list($username, $hostname) = $this->dbi->getCurrentUserAndHost();

            $row = $this->dbi->fetchSingleRow(
                'SELECT `plugin` FROM `mysql`.`user` WHERE '
                . '`User` = "' . $username . '" AND `Host` = "' . $hostname . '"'
            );
            if (is_array($row) && isset($row['plugin'])) {
                $authentication_plugin = $row['plugin'];
            }
        } elseif ($serverVersion >= 50702) {
            $row = $this->dbi->fetchSingleRow(
                'SELECT @@default_authentication_plugin'
            );
            $authentication_plugin = is_array($row) ? $row['@@default_authentication_plugin'] : null;
        }

        return $authentication_plugin;
    }

    /**
     * Returns all the grants for a certain user on a certain host
     * Used in the export privileges for all users section
     *
     * @param string $user User name
     * @param string $host Host name
     *
     * @return string containing all the grants text
     */
    public function getGrants($user, $host)
    {
        $grants = $this->dbi->fetchResult(
            "SHOW GRANTS FOR '"
            . $this->dbi->escapeString($user) . "'@'"
            . $this->dbi->escapeString($host) . "'"
        );
        $response = '';
        foreach ($grants as $one_grant) {
            $response .= $one_grant . ";\n\n";
        }
        return $response;
    }

    /**
     * Update password and get message for password updating
     *
     * @param string $err_url  error url
     * @param string $username username
     * @param string $hostname hostname
     *
     * @return Message success or error message after updating password
     */
    public function updatePassword($err_url, $username, $hostname)
    {
        // similar logic in /user_password
        $message = null;

        if (isset($_POST['pma_pw'], $_POST['pma_pw2']) && empty($_POST['nopass'])) {
            if ($_POST['pma_pw'] != $_POST['pma_pw2']) {
                $message = Message::error(__('The passwords aren\'t the same!'));
            } elseif (empty($_POST['pma_pw']) || empty($_POST['pma_pw2'])) {
                $message = Message::error(__('The password is empty!'));
            }
        }

        // here $nopass could be == 1
        if ($message === null) {
            $hashing_function = 'PASSWORD';
            $serverType = Util::getServerType();
            $serverVersion = $this->dbi->getVersion();
            $authentication_plugin
                = (isset($_POST['authentication_plugin'])
                ? $_POST['authentication_plugin']
                : $this->getCurrentAuthenticationPlugin(
                    'change',
                    $username,
                    $hostname
                ));

            // Use 'ALTER USER ...' syntax for MySQL 5.7.6+
            if ($serverType == 'MySQL'
                && $serverVersion >= 50706
            ) {
                if ($authentication_plugin != 'mysql_old_password') {
                    $query_prefix = "ALTER USER '"
                        . $this->dbi->escapeString($username)
                        . "'@'" . $this->dbi->escapeString($hostname) . "'"
                        . " IDENTIFIED WITH "
                        . $authentication_plugin
                        . " BY '";
                } else {
                    $query_prefix = "ALTER USER '"
                        . $this->dbi->escapeString($username)
                        . "'@'" . $this->dbi->escapeString($hostname) . "'"
                        . " IDENTIFIED BY '";
                }

                // in $sql_query which will be displayed, hide the password
                $sql_query = $query_prefix . "*'";

                $local_query = $query_prefix
                    . $this->dbi->escapeString($_POST['pma_pw']) . "'";
            } elseif ($serverType == 'MariaDB' && $serverVersion >= 10000) {
                // MariaDB uses "SET PASSWORD" syntax to change user password.
                // On Galera cluster only DDL queries are replicated, since
                // users are stored in MyISAM storage engine.
                $query_prefix = "SET PASSWORD FOR  '"
                    . $this->dbi->escapeString($username)
                    . "'@'" . $this->dbi->escapeString($hostname) . "'"
                    . " = PASSWORD ('";
                $sql_query = $local_query = $query_prefix
                    . $this->dbi->escapeString($_POST['pma_pw']) . "')";
            } elseif ($serverType == 'MariaDB'
                && $serverVersion >= 50200
                && $this->dbi->isSuperuser()
            ) {
                // Use 'UPDATE `mysql`.`user` ...' Syntax for MariaDB 5.2+
                if ($authentication_plugin == 'mysql_native_password') {
                    // Set the hashing method used by PASSWORD()
                    // to be 'mysql_native_password' type
                    $this->dbi->tryQuery('SET old_passwords = 0;');
                } elseif ($authentication_plugin == 'sha256_password') {
                    // Set the hashing method used by PASSWORD()
                    // to be 'sha256_password' type
                    $this->dbi->tryQuery('SET `old_passwords` = 2;');
                }

                $hashedPassword = $this->getHashedPassword($_POST['pma_pw']);

                $sql_query        = 'SET PASSWORD FOR \''
                    . $this->dbi->escapeString($username)
                    . '\'@\'' . $this->dbi->escapeString($hostname) . '\' = '
                    . ($_POST['pma_pw'] == ''
                        ? '\'\''
                        : $hashing_function . '(\''
                        . preg_replace('@.@s', '*', $_POST['pma_pw']) . '\')');

                $local_query = "UPDATE `mysql`.`user` SET "
                    . " `authentication_string` = '" . $hashedPassword
                    . "', `Password` = '', "
                    . " `plugin` = '" . $authentication_plugin . "'"
                    . " WHERE `User` = '" . $username . "' AND Host = '"
                    . $hostname . "';";
            } else {
                // USE 'SET PASSWORD ...' syntax for rest of the versions
                // Backup the old value, to be reset later
                $row = $this->dbi->fetchSingleRow(
                    'SELECT @@old_passwords;'
                );
                $orig_value = $row['@@old_passwords'];
                $update_plugin_query = "UPDATE `mysql`.`user` SET"
                    . " `plugin` = '" . $authentication_plugin . "'"
                    . " WHERE `User` = '" . $username . "' AND Host = '"
                    . $hostname . "';";

                // Update the plugin for the user
                if (! $this->dbi->tryQuery($update_plugin_query)) {
                    Util::mysqlDie(
                        $this->dbi->getError(),
                        $update_plugin_query,
                        false,
                        $err_url
                    );
                }
                $this->dbi->tryQuery("FLUSH PRIVILEGES;");

                if ($authentication_plugin == 'mysql_native_password') {
                    // Set the hashing method used by PASSWORD()
                    // to be 'mysql_native_password' type
                    $this->dbi->tryQuery('SET old_passwords = 0;');
                } elseif ($authentication_plugin == 'sha256_password') {
                    // Set the hashing method used by PASSWORD()
                    // to be 'sha256_password' type
                    $this->dbi->tryQuery('SET `old_passwords` = 2;');
                }
                $sql_query        = 'SET PASSWORD FOR \''
                    . $this->dbi->escapeString($username)
                    . '\'@\'' . $this->dbi->escapeString($hostname) . '\' = '
                    . ($_POST['pma_pw'] == ''
                        ? '\'\''
                        : $hashing_function . '(\''
                        . preg_replace('@.@s', '*', $_POST['pma_pw']) . '\')');

                $local_query      = 'SET PASSWORD FOR \''
                    . $this->dbi->escapeString($username)
                    . '\'@\'' . $this->dbi->escapeString($hostname) . '\' = '
                    . ($_POST['pma_pw'] == '' ? '\'\'' : $hashing_function
                    . '(\'' . $this->dbi->escapeString($_POST['pma_pw']) . '\')');
            }

            if (! $this->dbi->tryQuery($local_query)) {
                Util::mysqlDie(
                    $this->dbi->getError(),
                    $sql_query,
                    false,
                    $err_url
                );
            }
            // Flush privileges after successful password change
            $this->dbi->tryQuery("FLUSH PRIVILEGES;");

            $message = Message::success(
                __('The password for %s was changed successfully.')
            );
            $message->addParam('\'' . $username . '\'@\'' . $hostname . '\'');
            if (isset($orig_value)) {
                $this->dbi->tryQuery(
                    'SET `old_passwords` = ' . $orig_value . ';'
                );
            }
        }
        return $message;
    }

    /**
     * Revokes privileges and get message and SQL query for privileges revokes
     *
     * @param string $dbname    database name
     * @param string $tablename table name
     * @param string $username  username
     * @param string $hostname  host name
     * @param string $itemType  item type
     *
     * @return array ($message, $sql_query)
     */
    public function getMessageAndSqlQueryForPrivilegesRevoke(
        $dbname,
        $tablename,
        $username,
        $hostname,
        $itemType
    ) {
        $db_and_table = $this->wildcardEscapeForGrant($dbname, $tablename);

        $sql_query0 = 'REVOKE ALL PRIVILEGES ON ' . $itemType . ' ' . $db_and_table
            . ' FROM \''
            . $this->dbi->escapeString($username) . '\'@\''
            . $this->dbi->escapeString($hostname) . '\';';

        $sql_query1 = 'REVOKE GRANT OPTION ON ' . $itemType . ' ' . $db_and_table
            . ' FROM \'' . $this->dbi->escapeString($username) . '\'@\''
            . $this->dbi->escapeString($hostname) . '\';';

        $this->dbi->query($sql_query0);
        if (! $this->dbi->tryQuery($sql_query1)) {
            // this one may fail, too...
            $sql_query1 = '';
        }
        $sql_query = $sql_query0 . ' ' . $sql_query1;
        $message = Message::success(
            __('You have revoked the privileges for %s.')
        );
        $message->addParam('\'' . $username . '\'@\'' . $hostname . '\'');

        return [
            $message,
            $sql_query,
        ];
    }

    /**
     * Get REQUIRE cluase
     *
     * @return string REQUIRE clause
     */
    public function getRequireClause()
    {
        $arr = isset($_POST['ssl_type']) ? $_POST : $GLOBALS;
        if (isset($arr['ssl_type']) && $arr['ssl_type'] == 'SPECIFIED') {
            $require = [];
            if (! empty($arr['ssl_cipher'])) {
                $require[] = "CIPHER '"
                        . $this->dbi->escapeString($arr['ssl_cipher']) . "'";
            }
            if (! empty($arr['x509_issuer'])) {
                $require[] = "ISSUER '"
                        . $this->dbi->escapeString($arr['x509_issuer']) . "'";
            }
            if (! empty($arr['x509_subject'])) {
                $require[] = "SUBJECT '"
                        . $this->dbi->escapeString($arr['x509_subject']) . "'";
            }
            if (count($require)) {
                $require_clause = " REQUIRE " . implode(" AND ", $require);
            } else {
                $require_clause = " REQUIRE NONE";
            }
        } elseif (isset($arr['ssl_type']) && $arr['ssl_type'] == 'X509') {
            $require_clause = " REQUIRE X509";
        } elseif (isset($arr['ssl_type']) && $arr['ssl_type'] == 'ANY') {
            $require_clause = " REQUIRE SSL";
        } else {
            $require_clause = " REQUIRE NONE";
        }

        return $require_clause;
    }

    /**
     * Get a WITH clause for 'update privileges' and 'add user'
     *
     * @return string
     */
    public function getWithClauseForAddUserAndUpdatePrivs()
    {
        $sql_query = '';
        if (((isset($_POST['Grant_priv']) && $_POST['Grant_priv'] == 'Y')
            || (isset($GLOBALS['Grant_priv']) && $GLOBALS['Grant_priv'] == 'Y'))
            && ! ((Util::getServerType() == 'MySQL' || Util::getServerType() == 'Percona Server')
                && $this->dbi->getVersion() >= 80011)
        ) {
            $sql_query .= ' GRANT OPTION';
        }
        if (isset($_POST['max_questions']) || isset($GLOBALS['max_questions'])) {
            $max_questions = isset($_POST['max_questions'])
                ? (int) $_POST['max_questions'] : (int) $GLOBALS['max_questions'];
            $max_questions = max(0, $max_questions);
            $sql_query .= ' MAX_QUERIES_PER_HOUR ' . $max_questions;
        }
        if (isset($_POST['max_connections']) || isset($GLOBALS['max_connections'])) {
            $max_connections = isset($_POST['max_connections'])
                ? (int) $_POST['max_connections'] : (int) $GLOBALS['max_connections'];
            $max_connections = max(0, $max_connections);
            $sql_query .= ' MAX_CONNECTIONS_PER_HOUR ' . $max_connections;
        }
        if (isset($_POST['max_updates']) || isset($GLOBALS['max_updates'])) {
            $max_updates = isset($_POST['max_updates'])
                ? (int) $_POST['max_updates'] : (int) $GLOBALS['max_updates'];
            $max_updates = max(0, $max_updates);
            $sql_query .= ' MAX_UPDATES_PER_HOUR ' . $max_updates;
        }
        if (isset($_POST['max_user_connections'])
            || isset($GLOBALS['max_user_connections'])
        ) {
            $max_user_connections = isset($_POST['max_user_connections'])
                ? (int) $_POST['max_user_connections']
                : (int) $GLOBALS['max_user_connections'];
            $max_user_connections = max(0, $max_user_connections);
            $sql_query .= ' MAX_USER_CONNECTIONS ' . $max_user_connections;
        }
        return (! empty($sql_query) ? ' WITH' . $sql_query : '');
    }

    /**
     * Get HTML for addUsersForm, This function call if isset($_GET['adduser'])
     *
     * @param string $dbname database name
     *
     * @return string HTML for addUserForm
     */
    public function getHtmlForAddUser($dbname)
    {
        global $is_grantuser;

        $loginInformationFieldsNew = $this->getHtmlForLoginInformationFields('new');
        $privilegesTable = '';
        if ($is_grantuser) {
            $privilegesTable = $this->getHtmlToDisplayPrivilegesTable('*', '*', false);
        }

        return $this->template->render('server/privileges/add_user', [
            'database' => $dbname,
            'login_information_fields_new' => $loginInformationFieldsNew,
            'is_grant_user' => $is_grantuser,
            'privileges_table' => $privilegesTable,
        ]);
    }

    /**
     * Get the list of privileges and list of compared privileges as strings
     * and return a array that contains both strings
     *
     * @return array $list_of_privileges, $list_of_compared_privileges
     */
    public function getListOfPrivilegesAndComparedPrivileges()
    {
        $list_of_privileges
            = '`User`, '
            . '`Host`, '
            . '`Select_priv`, '
            . '`Insert_priv`, '
            . '`Update_priv`, '
            . '`Delete_priv`, '
            . '`Create_priv`, '
            . '`Drop_priv`, '
            . '`Grant_priv`, '
            . '`Index_priv`, '
            . '`Alter_priv`, '
            . '`References_priv`, '
            . '`Create_tmp_table_priv`, '
            . '`Lock_tables_priv`, '
            . '`Create_view_priv`, '
            . '`Show_view_priv`, '
            . '`Create_routine_priv`, '
            . '`Alter_routine_priv`, '
            . '`Execute_priv`';

        $listOfComparedPrivs
            = '`Select_priv` = \'N\''
            . ' AND `Insert_priv` = \'N\''
            . ' AND `Update_priv` = \'N\''
            . ' AND `Delete_priv` = \'N\''
            . ' AND `Create_priv` = \'N\''
            . ' AND `Drop_priv` = \'N\''
            . ' AND `Grant_priv` = \'N\''
            . ' AND `References_priv` = \'N\''
            . ' AND `Create_tmp_table_priv` = \'N\''
            . ' AND `Lock_tables_priv` = \'N\''
            . ' AND `Create_view_priv` = \'N\''
            . ' AND `Show_view_priv` = \'N\''
            . ' AND `Create_routine_priv` = \'N\''
            . ' AND `Alter_routine_priv` = \'N\''
            . ' AND `Execute_priv` = \'N\'';

        $list_of_privileges .=
            ', `Event_priv`, '
            . '`Trigger_priv`';
        $listOfComparedPrivs .=
            ' AND `Event_priv` = \'N\''
            . ' AND `Trigger_priv` = \'N\'';
        return [
            $list_of_privileges,
            $listOfComparedPrivs,
        ];
    }

    /**
     * Get the HTML for routine based privileges
     *
     * @param string $db             database name
     * @param string $index_checkbox starting index for rows to be added
     *
     * @return string
     */
    public function getHtmlTableBodyForSpecificDbRoutinePrivs($db, $index_checkbox)
    {
        $sql_query = 'SELECT * FROM `mysql`.`procs_priv` WHERE Db = \'' . $this->dbi->escapeString($db) . '\';';
        $res = $this->dbi->query($sql_query);
        $html_output = '';
        while ($row = $this->dbi->fetchAssoc($res)) {
            $html_output .= '<tr>';

            $html_output .= '<td';
            $value = htmlspecialchars($row['User'] . '&amp;#27;' . $row['Host']);
            $html_output .= '>';
            $html_output .= '<input type="checkbox" class="checkall" '
                . 'name="selected_usr[]" '
                . 'id="checkbox_sel_users_' . ($index_checkbox++) . '" '
                . 'value="' . $value . '"></td>';

            $html_output .= '<td>' . htmlspecialchars($row['User'])
                . '</td>'
                . '<td>' . htmlspecialchars($row['Host'])
                . '</td>'
                . '<td>routine'
                . '</td>'
                . '<td><code>' . htmlspecialchars($row['Routine_name']) . '</code>'
                . '</td>'
                . '<td>Yes'
                . '</td>';
            $current_user = $row['User'];
            $current_host = $row['Host'];
            $routine = $row['Routine_name'];
            $html_output .= '<td>';
            $specific_db = '';
            $specific_table = '';
            if ($GLOBALS['is_grantuser']) {
                $specific_db = isset($row['Db']) && $row['Db'] != '*'
                    ? $row['Db'] : '';
                $specific_table = isset($row['Table_name'])
                    && $row['Table_name'] != '*'
                    ? $row['Table_name'] : '';
                $html_output .= $this->getUserLink(
                    'edit',
                    $current_user,
                    $current_host,
                    $specific_db,
                    $specific_table,
                    $routine
                );
            }
            $html_output .= '</td>';
            $html_output .= '<td>';
            $html_output .= $this->getUserLink(
                'export',
                $current_user,
                $current_host,
                $specific_db,
                $specific_table,
                $routine
            );
            $html_output .= '</td>';

            $html_output .= '</tr>';
        }
        return $html_output;
    }

    /**
     * Get the HTML for user form and check the privileges for a particular database.
     *
     * @param string $db database name
     *
     * @return string
     */
    public function getHtmlForSpecificDbPrivileges(string $db): string
    {
        global $cfg, $pmaThemeImage, $text_dir, $is_createuser;

        $scriptName = Util::getScriptNameForOption(
            $cfg['DefaultTabDatabase'],
            'database'
        );

        $tableBody = '';
        if ($this->dbi->isSuperuser()) {
            $privMap = $this->getPrivMap($db);
            $tableBody = $this->getHtmlTableBodyForSpecificDbOrTablePrivs($privMap, $db);
        }

        $response = Response::getInstance();
        if ($response->isAjax() === true
            && empty($_REQUEST['ajax_page_request'])
        ) {
            $message = Message::success(__('User has been added.'));
            $response->addJSON('message', $message);
            exit;
        }

        return $this->template->render('server/privileges/database', [
            'is_superuser' => $this->dbi->isSuperuser(),
            'db' => $db,
            'database_url' => $scriptName,
            'pma_theme_image' => $pmaThemeImage,
            'text_dir' => $text_dir,
            'table_body' => $tableBody,
            'is_createuser' => $is_createuser,
        ]);
    }

    /**
     * Get the HTML for user form and check the privileges for a particular table.
     *
     * @param string $db    database name
     * @param string $table table name
     *
     * @return string
     */
    public function getHtmlForSpecificTablePrivileges($db, $table)
    {
        $html_output = '';
        if ($this->dbi->isSuperuser()) {
            // check the privileges for a particular table.
            $html_output  = '<form id="usersForm" action="' . Url::getFromRoute('/server/privileges') . '">';
            $html_output .= Url::getHiddenInputs($db, $table);
            $html_output .= '<fieldset>';
            $scriptName = Util::getScriptNameForOption(
                $GLOBALS['cfg']['DefaultTabTable'],
                'table'
            );
            $html_output .= '<legend>'
                . Util::getIcon('b_usrcheck')
                . sprintf(
                    __('Users having access to "%s"'),
                    '<a href="' . $scriptName
                    . Url::getCommon([
                        'db' => $db,
                        'table' => $table,
                    ], strpos($scriptName, '?') === false ? '?' : '&') . '">'
                    . htmlspecialchars($db) . '.' . htmlspecialchars($table)
                    . '</a>'
                )
                . '</legend>';

            $html_output .= '<div class="responsivetable jsresponsive">';
            $html_output .= '<table id="tablespecificuserrights" class="data">';
            $html_output .= $this->getHtmlForPrivsTableHead();
            $privMap = $this->getPrivMap($db);
            $sql_query = "SELECT `User`, `Host`, `Db`,"
                . " 't' AS `Type`, `Table_name`, `Table_priv`"
                . " FROM `mysql`.`tables_priv`"
                . " WHERE '" . $this->dbi->escapeString($db) . "' LIKE `Db`"
                . "     AND '" . $this->dbi->escapeString($table) . "' LIKE `Table_name`"
                . "     AND NOT (`Table_priv` = '' AND Column_priv = '')"
                . " ORDER BY `User` ASC, `Host` ASC, `Db` ASC, `Table_priv` ASC;";
            $res = $this->dbi->query($sql_query);
            $this->mergePrivMapFromResult($privMap, $res);
            $html_output .= $this->getHtmlTableBodyForSpecificDbOrTablePrivs($privMap, $db);
            $html_output .= '</table></div>';

            $html_output .= '<div class="floatleft">';
            $html_output .= $this->template->render('select_all', [
                'pma_theme_image' => $GLOBALS['pmaThemeImage'],
                'text_dir' => $GLOBALS['text_dir'],
                'form_name' => "usersForm",
            ]);
            $html_output .= Util::getButtonOrImage(
                'submit_mult',
                'mult_submit',
                __('Export'),
                'b_tblexport',
                'export'
            );

            $html_output .= '</fieldset>';
            $html_output .= '</form>';
        } else {
            $html_output .= $this->getHtmlForViewUsersError();
        }
        // Offer to create a new user for the current database
        $html_output .= $this->getAddUserHtmlFieldset($db, $table);
        return $html_output;
    }

    /**
     * gets privilege map
     *
     * @param string $db the database
     *
     * @return array the privilege map
     */
    public function getPrivMap($db)
    {
        list($listOfPrivs, $listOfComparedPrivs)
            = $this->getListOfPrivilegesAndComparedPrivileges();
        $sql_query
            = "("
            . " SELECT " . $listOfPrivs . ", '*' AS `Db`, 'g' AS `Type`"
            . " FROM `mysql`.`user`"
            . " WHERE NOT (" . $listOfComparedPrivs . ")"
            . ")"
            . " UNION "
            . "("
            . " SELECT " . $listOfPrivs . ", `Db`, 'd' AS `Type`"
            . " FROM `mysql`.`db`"
            . " WHERE '" . $this->dbi->escapeString($db) . "' LIKE `Db`"
            . "     AND NOT (" . $listOfComparedPrivs . ")"
            . ")"
            . " ORDER BY `User` ASC, `Host` ASC, `Db` ASC;";
        $res = $this->dbi->query($sql_query);
        $privMap = [];
        $this->mergePrivMapFromResult($privMap, $res);
        return $privMap;
    }

    /**
     * merge privilege map and rows from resultset
     *
     * @param array  $privMap the privilege map reference
     * @param object $result  the resultset of query
     *
     * @return void
     */
    public function mergePrivMapFromResult(array &$privMap, $result)
    {
        while ($row = $this->dbi->fetchAssoc($result)) {
            $user = $row['User'];
            $host = $row['Host'];
            if (! isset($privMap[$user])) {
                $privMap[$user] = [];
            }
            if (! isset($privMap[$user][$host])) {
                $privMap[$user][$host] = [];
            }
            $privMap[$user][$host][] = $row;
        }
    }

    /**
     * Get HTML snippet for privileges table head
     *
     * @return string
     */
    public function getHtmlForPrivsTableHead()
    {
        return '<thead>'
            . '<tr>'
            . '<th></th>'
            . '<th>' . __('User name') . '</th>'
            . '<th>' . __('Host name') . '</th>'
            . '<th>' . __('Type') . '</th>'
            . '<th>' . __('Privileges') . '</th>'
            . '<th>' . __('Grant') . '</th>'
            . '<th colspan="2">' . __('Action') . '</th>'
            . '</tr>'
            . '</thead>';
    }

    /**
     * Get HTML error for View Users form
     * For non superusers such as grant/create users
     *
     * @return string
     */
    public function getHtmlForViewUsersError()
    {
        return Message::error(
            __('Not enough privilege to view users.')
        )->getDisplay();
    }

    /**
     * Get HTML snippet for table body of specific database or table privileges
     *
     * @param array  $privMap privilege map
     * @param string $db      database
     *
     * @return string
     */
    public function getHtmlTableBodyForSpecificDbOrTablePrivs($privMap, $db)
    {
        $html_output = '<tbody>';
        $index_checkbox = 0;
        if (empty($privMap)) {
            $html_output .= '<tr>'
                . '<td colspan="6">'
                . __('No user found.')
                . '</td>'
                . '</tr>'
                . '</tbody>';
            return $html_output;
        }

        foreach ($privMap as $current_user => $val) {
            foreach ($val as $current_host => $current_privileges) {
                $nbPrivileges = count($current_privileges);
                $html_output .= '<tr>';

                $value = htmlspecialchars($current_user . '&amp;#27;' . $current_host);
                $html_output .= '<td';
                if ($nbPrivileges > 1) {
                    $html_output .= ' rowspan="' . $nbPrivileges . '"';
                }
                $html_output .= '>';
                $html_output .= '<input type="checkbox" class="checkall" '
                    . 'name="selected_usr[]" '
                    . 'id="checkbox_sel_users_' . ($index_checkbox++) . '" '
                    . 'value="' . $value . '"></td>' . "\n";

                // user
                $html_output .= '<td';
                if ($nbPrivileges > 1) {
                    $html_output .= ' rowspan="' . $nbPrivileges . '"';
                }
                $html_output .= '>';
                if (empty($current_user)) {
                    $html_output .= '<span style="color: #FF0000">'
                        . __('Any') . '</span>';
                } else {
                    $html_output .= htmlspecialchars($current_user);
                }
                $html_output .= '</td>';

                // host
                $html_output .= '<td';
                if ($nbPrivileges > 1) {
                    $html_output .= ' rowspan="' . $nbPrivileges . '"';
                }
                $html_output .= '>';
                $html_output .= htmlspecialchars($current_host);
                $html_output .= '</td>';

                $html_output .= $this->getHtmlListOfPrivs(
                    $db,
                    $current_privileges,
                    $current_user,
                    $current_host
                );
            }
        }

        //For fetching routine based privileges
        $html_output .= $this->getHtmlTableBodyForSpecificDbRoutinePrivs($db, $index_checkbox);
        $html_output .= '</tbody>';

        return $html_output;
    }

    /**
     * Get HTML to display privileges
     *
     * @param string $db                 Database name
     * @param array  $current_privileges List of privileges
     * @param string $current_user       Current user
     * @param string $current_host       Current host
     *
     * @return string HTML to display privileges
     */
    public function getHtmlListOfPrivs(
        $db,
        array $current_privileges,
        $current_user,
        $current_host
    ) {
        $nbPrivileges = count($current_privileges);
        $html_output = null;
        for ($i = 0; $i < $nbPrivileges; $i++) {
            $current = $current_privileges[$i];

            // type
            $html_output .= '<td>';
            if ($current['Type'] == 'g') {
                $html_output .= __('global');
            } elseif ($current['Type'] == 'd') {
                if ($current['Db'] == Util::escapeMysqlWildcards($db)) {
                    $html_output .= __('database-specific');
                } else {
                    $html_output .= __('wildcard') . ': '
                        . '<code>'
                        . htmlspecialchars($current['Db'])
                        . '</code>';
                }
            } elseif ($current['Type'] == 't') {
                $html_output .= __('table-specific');
            }
            $html_output .= '</td>';

            // privileges
            $html_output .= '<td>';
            if (isset($current['Table_name'])) {
                $privList = explode(',', $current['Table_priv']);
                $privs = [];
                $grantsArr = $this->getTableGrantsArray();
                foreach ($grantsArr as $grant) {
                    $privs[$grant[0]] = 'N';
                    foreach ($privList as $priv) {
                        if ($grant[0] == $priv) {
                            $privs[$grant[0]] = 'Y';
                        }
                    }
                }
                $html_output .= '<code>'
                    . implode(
                        ',',
                        $this->extractPrivInfo($privs, true, true)
                    )
                    . '</code>';
            } else {
                $html_output .= '<code>'
                    . implode(
                        ',',
                        $this->extractPrivInfo($current, true, false)
                    )
                    . '</code>';
            }
            $html_output .= '</td>';

            // grant
            $html_output .= '<td>';
            $containsGrant = false;
            if (isset($current['Table_name'])) {
                $privList = explode(',', $current['Table_priv']);
                foreach ($privList as $priv) {
                    if ($priv == 'Grant') {
                        $containsGrant = true;
                    }
                }
            } else {
                $containsGrant = $current['Grant_priv'] == 'Y';
            }
            $html_output .= ($containsGrant ? __('Yes') : __('No'));
            $html_output .= '</td>';

            // action
            $html_output .= '<td>';
            $specific_db = isset($current['Db']) && $current['Db'] != '*'
                ? $current['Db'] : '';
            $specific_table = isset($current['Table_name'])
                && $current['Table_name'] != '*'
                ? $current['Table_name'] : '';
            if ($GLOBALS['is_grantuser']) {
                $html_output .= $this->getUserLink(
                    'edit',
                    $current_user,
                    $current_host,
                    $specific_db,
                    $specific_table
                );
            }
            $html_output .= '</td>';
            $html_output .= '<td class="center">'
                . $this->getUserLink(
                    'export',
                    $current_user,
                    $current_host,
                    $specific_db,
                    $specific_table
                )
                . '</td>';

            $html_output .= '</tr>';
            if (($i + 1) < $nbPrivileges) {
                $html_output .= '<tr class="noclick">';
            }
        }
        return $html_output;
    }

    /**
     * Returns edit, revoke or export link for a user.
     *
     * @param string $linktype    The link type (edit | revoke | export)
     * @param string $username    User name
     * @param string $hostname    Host name
     * @param string $dbname      Database name
     * @param string $tablename   Table name
     * @param string $routinename Routine name
     * @param string $initial     Initial value
     *
     * @return string HTML code with link
     */
    public function getUserLink(
        $linktype,
        $username,
        $hostname,
        $dbname = '',
        $tablename = '',
        $routinename = '',
        $initial = ''
    ) {
        $html = '<a';
        switch ($linktype) {
            case 'edit':
                $html .= ' class="edit_user_anchor"';
                break;
            case 'export':
                $html .= ' class="export_user_anchor ajax"';
                break;
        }
        $params = [
            'username' => $username,
            'hostname' => $hostname,
        ];
        switch ($linktype) {
            case 'edit':
                $params['dbname'] = $dbname;
                $params['tablename'] = $tablename;
                $params['routinename'] = $routinename;
                break;
            case 'revoke':
                $params['dbname'] = $dbname;
                $params['tablename'] = $tablename;
                $params['routinename'] = $routinename;
                $params['revokeall'] = 1;
                break;
            case 'export':
                $params['initial'] = $initial;
                $params['export'] = 1;
                break;
        }

        $html .= ' href="' . Url::getFromRoute('/server/privileges');
        if ($linktype == 'revoke') {
            $html .= '" data-post="' . Url::getCommon($params, '');
        } else {
            $html .= Url::getCommon($params, '&');
        }
        $html .= '">';

        switch ($linktype) {
            case 'edit':
                $html .= Util::getIcon('b_usredit', __('Edit privileges'));
                break;
            case 'revoke':
                $html .= Util::getIcon('b_usrdrop', __('Revoke'));
                break;
            case 'export':
                $html .= Util::getIcon('b_tblexport', __('Export'));
                break;
        }
        $html .= '</a>';

        return $html;
    }

    /**
     * Returns user group edit link
     *
     * @param string $username User name
     *
     * @return string HTML code with link
     */
    public function getUserGroupEditLink($username)
    {
         return '<a class="edit_user_group_anchor ajax"'
            . ' href="' . Url::getFromRoute('/server/privileges', ['username' => $username])
            . '">'
            . Util::getIcon('b_usrlist', __('Edit user group'))
            . '</a>';
    }

    /**
     * Returns number of defined user groups
     *
     * @return integer
     */
    public function getUserGroupCount()
    {
        $cfgRelation = $this->relation->getRelationsParam();
        $user_group_table = Util::backquote($cfgRelation['db'])
            . '.' . Util::backquote($cfgRelation['usergroups']);
        $sql_query = 'SELECT COUNT(*) FROM ' . $user_group_table;
        $user_group_count = $this->dbi->fetchValue(
            $sql_query,
            0,
            0,
            DatabaseInterface::CONNECT_CONTROL
        );

        return $user_group_count;
    }

    /**
     * Returns name of user group that user is part of
     *
     * @param string $username User name
     *
     * @return mixed usergroup if found or null if not found
     */
    public function getUserGroupForUser($username)
    {
        $cfgRelation = $this->relation->getRelationsParam();

        if (empty($cfgRelation['db'])
            || empty($cfgRelation['users'])
        ) {
            return null;
        }

        $user_table = Util::backquote($cfgRelation['db'])
            . '.' . Util::backquote($cfgRelation['users']);
        $sql_query = 'SELECT `usergroup` FROM ' . $user_table
            . ' WHERE `username` = \'' . $username . '\''
            . ' LIMIT 1';

        $usergroup = $this->dbi->fetchValue(
            $sql_query,
            0,
            0,
            DatabaseInterface::CONNECT_CONTROL
        );

        if ($usergroup === false) {
            return null;
        }

        return $usergroup;
    }

    /**
     * This function return the extra data array for the ajax behavior
     *
     * @param string $password  password
     * @param string $sql_query sql query
     * @param string $hostname  hostname
     * @param string $username  username
     *
     * @return array
     */
    public function getExtraDataForAjaxBehavior(
        $password,
        $sql_query,
        $hostname,
        $username
    ) {
        if (isset($GLOBALS['dbname'])) {
            //if (preg_match('/\\\\(?:_|%)/i', $dbname)) {
            if (preg_match('/(?<!\\\\)(?:_|%)/', $GLOBALS['dbname'])) {
                $dbname_is_wildcard = true;
            } else {
                $dbname_is_wildcard = false;
            }
        }

        $user_group_count = 0;
        if ($GLOBALS['cfgRelation']['menuswork']) {
            $user_group_count = $this->getUserGroupCount();
        }

        $extra_data = [];
        if (strlen($sql_query) > 0) {
            $extra_data['sql_query'] = Util::getMessage(null, $sql_query);
        }

        if (isset($_POST['change_copy'])) {
            /**
             * generate html on the fly for the new user that was just created.
             */
            $new_user_string = '<tr>' . "\n"
                . '<td> <input type="checkbox" name="selected_usr[]" '
                . 'id="checkbox_sel_users_"'
                . 'value="'
                . htmlspecialchars($username)
                . '&amp;#27;' . htmlspecialchars($hostname) . '">'
                . '</td>' . "\n"
                . '<td><label for="checkbox_sel_users_">'
                . (empty($_POST['username'])
                        ? '<span style="color: #FF0000">' . __('Any') . '</span>'
                        : htmlspecialchars($username) ) . '</label></td>' . "\n"
                . '<td>' . htmlspecialchars($hostname) . '</td>' . "\n";

            $new_user_string .= '<td>';

            if (! empty($password) || isset($_POST['pma_pw'])) {
                $new_user_string .= __('Yes');
            } else {
                $new_user_string .= '<span style="color: #FF0000">'
                    . __('No')
                . '</span>';
            }

            $new_user_string .= '</td>' . "\n";
            $new_user_string .= '<td>'
                . '<code>' . implode(', ', $this->extractPrivInfo(null, true)) . '</code>'
                . '</td>'; //Fill in privileges here

            // if $cfg['Servers'][$i]['users'] and $cfg['Servers'][$i]['usergroups'] are
            // enabled
            $cfgRelation = $this->relation->getRelationsParam();
            if (! empty($cfgRelation['users']) && ! empty($cfgRelation['usergroups'])) {
                $new_user_string .= '<td class="usrGroup"></td>';
            }

            $new_user_string .= '<td>';
            if (isset($_POST['Grant_priv']) && $_POST['Grant_priv'] == 'Y') {
                $new_user_string .= __('Yes');
            } else {
                $new_user_string .= __('No');
            }
            $new_user_string .= '</td>';

            if ($GLOBALS['is_grantuser']) {
                $new_user_string .= '<td>'
                    . $this->getUserLink('edit', $username, $hostname)
                    . '</td>' . "\n";
            }

            if ($cfgRelation['menuswork'] && $user_group_count > 0) {
                $new_user_string .= '<td>'
                    . $this->getUserGroupEditLink($username)
                    . '</td>' . "\n";
            }

            $new_user_string .= '<td>'
                . $this->getUserLink(
                    'export',
                    $username,
                    $hostname,
                    '',
                    '',
                    '',
                    isset($_GET['initial']) ? $_GET['initial'] : ''
                )
                . '</td>' . "\n";

            $new_user_string .= '</tr>';

            $extra_data['new_user_string'] = $new_user_string;

            /**
             * Generate the string for this alphabet's initial, to update the user
             * pagination
             */
            $new_user_initial = mb_strtoupper(
                mb_substr($username, 0, 1)
            );
            $newUserInitialString = '<a href="' . Url::getFromRoute('/server/privileges', ['initial' => $new_user_initial]) . '">'
                . $new_user_initial . '</a>';
            $extra_data['new_user_initial'] = $new_user_initial;
            $extra_data['new_user_initial_string'] = $newUserInitialString;
        }

        if (isset($_POST['update_privs'])) {
            $extra_data['db_specific_privs'] = false;
            $extra_data['db_wildcard_privs'] = false;
            if (isset($dbname_is_wildcard)) {
                $extra_data['db_specific_privs'] = ! $dbname_is_wildcard;
                $extra_data['db_wildcard_privs'] = $dbname_is_wildcard;
            }
            $new_privileges = implode(', ', $this->extractPrivInfo(null, true));

            $extra_data['new_privileges'] = $new_privileges;
        }

        if (isset($_GET['validate_username'])) {
            $sql_query = "SELECT * FROM `mysql`.`user` WHERE `User` = '"
                . $_GET['username'] . "';";
            $res = $this->dbi->query($sql_query);
            $row = $this->dbi->fetchRow($res);
            if (empty($row)) {
                $extra_data['user_exists'] = false;
            } else {
                $extra_data['user_exists'] = true;
            }
        }

        return $extra_data;
    }

    /**
     * Get the HTML snippet for change user login information
     *
     * @param string $username username
     * @param string $hostname host name
     *
     * @return string HTML snippet
     */
    public function getChangeLoginInformationHtmlForm($username, $hostname)
    {
        $choices = [
            '4' => __('… keep the old one.'),
            '1' => __('… delete the old one from the user tables.'),
            '2' => __(
                '… revoke all active privileges from '
                . 'the old one and delete it afterwards.'
            ),
            '3' => __(
                '… delete the old one from the user tables '
                . 'and reload the privileges afterwards.'
            ),
        ];

        $html_output = '<form action="' . Url::getFromRoute('/server/privileges')
            . '" onsubmit="return checkAddUser(this);" '
            . 'method="post" class="copyUserForm submenu-item">' . "\n"
            . Url::getHiddenInputs('', '')
            . '<input type="hidden" name="old_username" '
            . 'value="' . htmlspecialchars($username) . '">' . "\n"
            . '<input type="hidden" name="old_hostname" '
            . 'value="' . htmlspecialchars($hostname) . '">' . "\n";

        $usergroup = $this->getUserGroupForUser($username);
        if ($usergroup !== null) {
            $html_output .= '<input type="hidden" name="old_usergroup" '
            . 'value="' . htmlspecialchars($usergroup) . '">' . "\n";
        }

        $html_output .= '<fieldset id="fieldset_change_copy_user">' . "\n"
            . '<legend data-submenu-label="' . __('Login Information') . '">' . "\n"
            . __('Change login information / Copy user account')
            . '</legend>' . "\n"
            . $this->getHtmlForLoginInformationFields('change', $username, $hostname);

        $html_output .= '<fieldset id="fieldset_mode">' . "\n"
            . ' <legend>'
            . __('Create a new user account with the same privileges and …')
            . '</legend>' . "\n";
        $html_output .= Util::getRadioFields(
            'mode',
            $choices,
            '4',
            true
        );
        $html_output .= '</fieldset>' . "\n"
           . '</fieldset>' . "\n";

        $html_output .= '<fieldset id="fieldset_change_copy_user_footer" '
            . 'class="tblFooters">' . "\n"
            . '<input type="hidden" name="change_copy" value="1">' . "\n"
            . '<input class="btn btn-primary" type="submit" value="' . __('Go') . '">' . "\n"
            . '</fieldset>' . "\n"
            . '</form>' . "\n";

        return $html_output;
    }

    /**
     * Provide a line with links to the relevant database and table
     *
     * @param string $url_dbname url database name that urlencode() string
     * @param string $dbname     database name
     * @param string $tablename  table name
     *
     * @return string HTML snippet
     */
    public function getLinkToDbAndTable($url_dbname, $dbname, $tablename)
    {
        $scriptName = Util::getScriptNameForOption(
            $GLOBALS['cfg']['DefaultTabDatabase'],
            'database'
        );
        $html_output = '[ ' . __('Database')
            . ' <a href="' . $scriptName
            . Url::getCommon([
                'db' => $url_dbname,
                'reload' => 1,
            ], strpos($scriptName, '?') === false ? '?' : '&')
            . '">'
            . htmlspecialchars(Util::unescapeMysqlWildcards($dbname)) . ': '
            . Util::getTitleForTarget(
                $GLOBALS['cfg']['DefaultTabDatabase']
            )
            . "</a> ]\n";

        if (strlen($tablename) > 0) {
            $scriptName = Util::getScriptNameForOption(
                $GLOBALS['cfg']['DefaultTabTable'],
                'table'
            );
            $html_output .= ' [ ' . __('Table') . ' <a href="'
                . $scriptName
                . Url::getCommon([
                    'db' => $url_dbname,
                    'table' => $tablename,
                    'reload' => 1,
                ], strpos($scriptName, '?') === false ? '?' : '&')
                . '">' . htmlspecialchars($tablename) . ': '
                . Util::getTitleForTarget(
                    $GLOBALS['cfg']['DefaultTabTable']
                )
                . "</a> ]\n";
        }
        return $html_output;
    }

    /**
     * no db name given, so we want all privs for the given user
     * db name was given, so we want all user specific rights for this db
     * So this function returns user rights as an array
     *
     * @param string $username username
     * @param string $hostname host name
     * @param string $type     database or table
     * @param string $dbname   database name
     *
     * @return array database rights
     */
    public function getUserSpecificRights($username, $hostname, $type, $dbname = '')
    {
        $user_host_condition = " WHERE `User`"
            . " = '" . $this->dbi->escapeString($username) . "'"
            . " AND `Host`"
            . " = '" . $this->dbi->escapeString($hostname) . "'";

        if ($type == 'database') {
            $tables_to_search_for_users = [
                'tables_priv',
                'columns_priv',
                'procs_priv',
            ];
            $dbOrTableName = 'Db';
        } elseif ($type == 'table') {
            $user_host_condition .= " AND `Db` LIKE '"
                . $this->dbi->escapeString($dbname) . "'";
            $tables_to_search_for_users = ['columns_priv'];
            $dbOrTableName = 'Table_name';
        } else { // routine
            $user_host_condition .= " AND `Db` LIKE '"
                . $this->dbi->escapeString($dbname) . "'";
            $tables_to_search_for_users = ['procs_priv'];
            $dbOrTableName = 'Routine_name';
        }

        // we also want privileges for this user not in table `db` but in other table
        $tables = $this->dbi->fetchResult('SHOW TABLES FROM `mysql`;');

        $db_rights_sqls = [];
        foreach ($tables_to_search_for_users as $table_search_in) {
            if (in_array($table_search_in, $tables)) {
                $db_rights_sqls[] = '
                    SELECT DISTINCT `' . $dbOrTableName . '`
                    FROM `mysql`.' . Util::backquote($table_search_in)
                   . $user_host_condition;
            }
        }

        $user_defaults = [
            $dbOrTableName  => '',
            'Grant_priv'    => 'N',
            'privs'         => ['USAGE'],
            'Column_priv'   => true,
        ];

        // for the rights
        $db_rights = [];

        $db_rights_sql = '(' . implode(') UNION (', $db_rights_sqls) . ')'
            . ' ORDER BY `' . $dbOrTableName . '` ASC';

        $db_rights_result = $this->dbi->query($db_rights_sql);

        while ($db_rights_row = $this->dbi->fetchAssoc($db_rights_result)) {
            $db_rights_row = array_merge($user_defaults, $db_rights_row);
            if ($type == 'database') {
                // only Db names in the table `mysql`.`db` uses wildcards
                // as we are in the db specific rights display we want
                // all db names escaped, also from other sources
                $db_rights_row['Db'] = Util::escapeMysqlWildcards(
                    $db_rights_row['Db']
                );
            }
            $db_rights[$db_rights_row[$dbOrTableName]] = $db_rights_row;
        }

        $this->dbi->freeResult($db_rights_result);

        if ($type == 'database') {
            $sql_query = 'SELECT * FROM `mysql`.`db`'
                . $user_host_condition . ' ORDER BY `Db` ASC';
        } elseif ($type == 'table') {
            $sql_query = 'SELECT `Table_name`,'
                . ' `Table_priv`,'
                . ' IF(`Column_priv` = _latin1 \'\', 0, 1)'
                . ' AS \'Column_priv\''
                . ' FROM `mysql`.`tables_priv`'
                . $user_host_condition
                . ' ORDER BY `Table_name` ASC;';
        } else {
            $sql_query = "SELECT `Routine_name`, `Proc_priv`"
                . " FROM `mysql`.`procs_priv`"
                . $user_host_condition
                . " ORDER BY `Routine_name`";
        }

        $result = $this->dbi->query($sql_query);

        while ($row = $this->dbi->fetchAssoc($result)) {
            if (isset($db_rights[$row[$dbOrTableName]])) {
                $db_rights[$row[$dbOrTableName]]
                    = array_merge($db_rights[$row[$dbOrTableName]], $row);
            } else {
                $db_rights[$row[$dbOrTableName]] = $row;
            }
            if ($type == 'database') {
                // there are db specific rights for this user
                // so we can drop this db rights
                $db_rights[$row['Db']]['can_delete'] = true;
            }
        }
        $this->dbi->freeResult($result);
        return $db_rights;
    }

    /**
     * Parses Proc_priv data
     *
     * @param string $privs Proc_priv
     *
     * @return array
     */
    public function parseProcPriv($privs)
    {
        $result = [
            'Alter_routine_priv' => 'N',
            'Execute_priv'       => 'N',
            'Grant_priv'         => 'N',
        ];
        foreach (explode(',', (string) $privs) as $priv) {
            if ($priv == 'Alter Routine') {
                $result['Alter_routine_priv'] = 'Y';
            } else {
                $result[$priv . '_priv'] = 'Y';
            }
        }
        return $result;
    }

    /**
     * Get a HTML table for display user's tabel specific or database specific rights
     *
     * @param string $username username
     * @param string $hostname host name
     * @param string $type     database, table or routine
     * @param string $dbname   database name
     *
     * @return string
     */
    public function getHtmlForAllTableSpecificRights(
        $username,
        $hostname,
        $type,
        $dbname = ''
    ) {
        $uiData = [
            'database' => [
                'form_id'        => 'database_specific_priv',
                'sub_menu_label' => __('Database'),
                'legend'         => __('Database-specific privileges'),
                'type_label'     => __('Database'),
            ],
            'table' => [
                'form_id'        => 'table_specific_priv',
                'sub_menu_label' => __('Table'),
                'legend'         => __('Table-specific privileges'),
                'type_label'     => __('Table'),
            ],
            'routine' => [
                'form_id'        => 'routine_specific_priv',
                'sub_menu_label' => __('Routine'),
                'legend'         => __('Routine-specific privileges'),
                'type_label'     => __('Routine'),
            ],
        ];

        /**
         * no db name given, so we want all privs for the given user
         * db name was given, so we want all user specific rights for this db
         */
        $db_rights = $this->getUserSpecificRights($username, $hostname, $type, $dbname);
        ksort($db_rights);

        $foundRows = [];
        $privileges = [];
        foreach ($db_rights as $row) {
            $onePrivilege = [];

            $paramTableName = '';
            $paramRoutineName = '';

            if ($type == 'database') {
                $name = $row['Db'];
                $onePrivilege['grant']        = $row['Grant_priv'] == 'Y';
                $onePrivilege['table_privs']   = ! empty($row['Table_priv'])
                    || ! empty($row['Column_priv']);
                $onePrivilege['privileges'] = implode(',', $this->extractPrivInfo($row, true));

                $paramDbName = $row['Db'];
            } elseif ($type == 'table') {
                $name = $row['Table_name'];
                $onePrivilege['grant'] = in_array(
                    'Grant',
                    explode(',', $row['Table_priv'])
                );
                $onePrivilege['column_privs']  = ! empty($row['Column_priv']);
                $onePrivilege['privileges'] = implode(',', $this->extractPrivInfo($row, true));

                $paramDbName = $dbname;
                $paramTableName = $row['Table_name'];
            } else { // routine
                $name = $row['Routine_name'];
                $onePrivilege['grant'] = in_array(
                    'Grant',
                    explode(',', $row['Proc_priv'])
                );

                $privs = $this->parseProcPriv($row['Proc_priv']);
                $onePrivilege['privileges'] = implode(
                    ',',
                    $this->extractPrivInfo($privs, true)
                );

                $paramDbName = $dbname;
                $paramRoutineName = $row['Routine_name'];
            }

            $foundRows[] = $name;
            $onePrivilege['name'] = $name;

            $onePrivilege['edit_link'] = '';
            if ($GLOBALS['is_grantuser']) {
                $onePrivilege['edit_link'] = $this->getUserLink(
                    'edit',
                    $username,
                    $hostname,
                    $paramDbName,
                    $paramTableName,
                    $paramRoutineName
                );
            }

            $onePrivilege['revoke_link'] = '';
            if ($type != 'database' || ! empty($row['can_delete'])) {
                $onePrivilege['revoke_link'] = $this->getUserLink(
                    'revoke',
                    $username,
                    $hostname,
                    $paramDbName,
                    $paramTableName,
                    $paramRoutineName
                );
            }

            $privileges[] = $onePrivilege;
        }

        $data = $uiData[$type];
        $data['privileges'] = $privileges;
        $data['username']   = $username;
        $data['hostname']   = $hostname;
        $data['database']   = $dbname;
        $data['type']       = $type;

        if ($type == 'database') {
            // we already have the list of databases from libraries/common.inc.php
            // via $pma = new PMA;
            $pred_db_array = $GLOBALS['dblist']->databases;
            $databases_to_skip = [
                'information_schema',
                'performance_schema',
            ];

            $databases = [];
            if (! empty($pred_db_array)) {
                foreach ($pred_db_array as $current_db) {
                    if (in_array($current_db, $databases_to_skip)) {
                        continue;
                    }
                    $current_db_escaped = Util::escapeMysqlWildcards($current_db);
                    // cannot use array_diff() once, outside of the loop,
                    // because the list of databases has special characters
                    // already escaped in $foundRows,
                    // contrary to the output of SHOW DATABASES
                    if (! in_array($current_db_escaped, $foundRows)) {
                        $databases[] = $current_db;
                    }
                }
            }
            $data['databases'] = $databases;
        } elseif ($type == 'table') {
            $result = @$this->dbi->tryQuery(
                "SHOW TABLES FROM " . Util::backquote($dbname),
                DatabaseInterface::CONNECT_USER,
                DatabaseInterface::QUERY_STORE
            );

            $tables = [];
            if ($result) {
                while ($row = $this->dbi->fetchRow($result)) {
                    if (! in_array($row[0], $foundRows)) {
                        $tables[] = $row[0];
                    }
                }
                $this->dbi->freeResult($result);
            }
            $data['tables'] = $tables;
        } else { // routine
            $routineData = $this->dbi->getRoutines($dbname);

            $routines = [];
            foreach ($routineData as $routine) {
                if (! in_array($routine['name'], $foundRows)) {
                    $routines[] = $routine['name'];
                }
            }
            $data['routines'] = $routines;
        }

        return $this->template->render('server/privileges/privileges_summary', $data);
    }

    /**
     * Get HTML for display the users overview
     * (if less than 50 users, display them immediately)
     *
     * @param array  $result        ran sql query
     * @param array  $db_rights     user's database rights array
     * @param string $pmaThemeImage a image source link
     * @param string $text_dir      text directory
     *
     * @return string HTML snippet
     */
    public function getUsersOverview($result, array $db_rights, $pmaThemeImage, $text_dir)
    {
        global $is_grantuser, $is_createuser;

        $cfgRelation = $this->relation->getRelationsParam();

        while ($row = $this->dbi->fetchAssoc($result)) {
            $row['privs'] = $this->extractPrivInfo($row, true);
            $db_rights[$row['User']][$row['Host']] = $row;
        }
        $this->dbi->freeResult($result);

        $user_group_count = 0;
        if ($cfgRelation['menuswork']) {
            $sql_query = 'SELECT * FROM ' . Util::backquote($cfgRelation['db'])
                . '.' . Util::backquote($cfgRelation['users']);
            $result = $this->relation->queryAsControlUser($sql_query, false);
            $group_assignment = [];
            if ($result) {
                while ($row = $this->dbi->fetchAssoc($result)) {
                    $group_assignment[$row['username']] = $row['usergroup'];
                }
            }
            $this->dbi->freeResult($result);

            $user_group_count = $this->getUserGroupCount();
        }

        $hosts = [];
        foreach ($db_rights as $user) {
            ksort($user);
            foreach ($user as $host) {
                $check_plugin_query = "SELECT * FROM `mysql`.`user` WHERE "
                    . "`User` = '" . $host['User'] . "' AND `Host` = '"
                    . $host['Host'] . "'";
                $res = $this->dbi->fetchSingleRow($check_plugin_query);

                $hasPassword = false;
                if ((isset($res['authentication_string'])
                    && ! empty($res['authentication_string']))
                    || (isset($res['Password'])
                    && ! empty($res['Password']))
                ) {
                    $hasPassword = true;
                }

                $hosts[] = [
                    'user' => $host['User'],
                    'host' => $host['Host'],
                    'has_password' => $hasPassword,
                    'has_select_priv' => isset($host['Select_priv']),
                    'privileges' => $host['privs'],
                    'group' => $group_assignment[$host['User']] ?? '',
                    'has_grant' => $host['Grant_priv'] == 'Y',
                ];
            }
        }

        return $this->template->render('server/privileges/users_overview', [
            'menus_work' => $cfgRelation['menuswork'],
            'user_group_count' => $user_group_count,
            'pma_theme_image' => $pmaThemeImage,
            'text_dir' => $text_dir,
            'initial' => $_GET['initial'] ?? '',
            'hosts' => $hosts,
            'is_grantuser' => $is_grantuser,
            'is_createuser' => $is_createuser,
        ]);
    }

    /**
     * Get HTML for Displays the initials
     *
     * @param array $array_initials array for all initials, even non A-Z
     *
     * @return string HTML snippet
     */
    public function getHtmlForInitials(array $array_initials)
    {
        // initialize to false the letters A-Z
        for ($letter_counter = 1; $letter_counter < 27; $letter_counter++) {
            if (! isset($array_initials[mb_chr($letter_counter + 64)])) {
                $array_initials[mb_chr($letter_counter + 64)] = false;
            }
        }

        $initials = $this->dbi->tryQuery(
            'SELECT DISTINCT UPPER(LEFT(`User`,1)) FROM `user`'
            . ' ORDER BY UPPER(LEFT(`User`,1)) ASC',
            DatabaseInterface::CONNECT_USER,
            DatabaseInterface::QUERY_STORE
        );
        if ($initials) {
            while (list($tmp_initial) = $this->dbi->fetchRow($initials)) {
                $array_initials[$tmp_initial] = true;
            }
        }

        // Display the initials, which can be any characters, not
        // just letters. For letters A-Z, we add the non-used letters
        // as greyed out.

        uksort($array_initials, "strnatcasecmp");

        return $this->template->render('server/privileges/initials_row', [
            'array_initials' => $array_initials,
            'initial' => isset($_GET['initial']) ? $_GET['initial'] : null,
        ]);
    }

    /**
     * Get the database rights array for Display user overview
     *
     * @return array    database rights array
     */
    public function getDbRightsForUserOverview()
    {
        // we also want users not in table `user` but in other table
        $tables = $this->dbi->fetchResult('SHOW TABLES FROM `mysql`;');

        $tablesSearchForUsers = [
            'user',
            'db',
            'tables_priv',
            'columns_priv',
            'procs_priv',
        ];

        $db_rights_sqls = [];
        foreach ($tablesSearchForUsers as $table_search_in) {
            if (in_array($table_search_in, $tables)) {
                $db_rights_sqls[] = 'SELECT DISTINCT `User`, `Host` FROM `mysql`.`'
                    . $table_search_in . '` '
                    . (isset($_GET['initial'])
                    ? $this->rangeOfUsers($_GET['initial'])
                    : '');
            }
        }
        $user_defaults = [
            'User'       => '',
            'Host'       => '%',
            'Password'   => '?',
            'Grant_priv' => 'N',
            'privs'      => ['USAGE'],
        ];

        // for the rights
        $db_rights = [];

        $db_rights_sql = '(' . implode(') UNION (', $db_rights_sqls) . ')'
            . ' ORDER BY `User` ASC, `Host` ASC';

        $db_rights_result = $this->dbi->query($db_rights_sql);

        while ($db_rights_row = $this->dbi->fetchAssoc($db_rights_result)) {
            $db_rights_row = array_merge($user_defaults, $db_rights_row);
            $db_rights[$db_rights_row['User']][$db_rights_row['Host']]
                = $db_rights_row;
        }
        $this->dbi->freeResult($db_rights_result);
        ksort($db_rights);

        return $db_rights;
    }

    /**
     * Delete user and get message and sql query for delete user in privileges
     *
     * @param array $queries queries
     *
     * @return array Message
     */
    public function deleteUser(array $queries)
    {
        $sql_query = '';
        if (empty($queries)) {
            $message = Message::error(__('No users selected for deleting!'));
        } else {
            if ($_POST['mode'] == 3) {
                $queries[] = '# ' . __('Reloading the privileges') . ' …';
                $queries[] = 'FLUSH PRIVILEGES;';
            }
            $drop_user_error = '';
            foreach ($queries as $sql_query) {
                if ($sql_query[0] != '#') {
                    if (! $this->dbi->tryQuery($sql_query)) {
                        $drop_user_error .= $this->dbi->getError() . "\n";
                    }
                }
            }
            // tracking sets this, causing the deleted db to be shown in navi
            unset($GLOBALS['db']);

            $sql_query = implode("\n", $queries);
            if (! empty($drop_user_error)) {
                $message = Message::rawError($drop_user_error);
            } else {
                $message = Message::success(
                    __('The selected users have been deleted successfully.')
                );
            }
        }
        return [
            $sql_query,
            $message,
        ];
    }

    /**
     * Update the privileges and return the success or error message
     *
     * @param string $username  username
     * @param string $hostname  host name
     * @param string $tablename table name
     * @param string $dbname    database name
     * @param string $itemType  item type
     *
     * @return array success message or error message for update
     */
    public function updatePrivileges($username, $hostname, $tablename, $dbname, $itemType)
    {
        $db_and_table = $this->wildcardEscapeForGrant($dbname, $tablename);

        $sql_query0 = 'REVOKE ALL PRIVILEGES ON ' . $itemType . ' ' . $db_and_table
            . ' FROM \'' . $this->dbi->escapeString($username)
            . '\'@\'' . $this->dbi->escapeString($hostname) . '\';';

        if (! isset($_POST['Grant_priv']) || $_POST['Grant_priv'] != 'Y') {
            $sql_query1 = 'REVOKE GRANT OPTION ON ' . $itemType . ' ' . $db_and_table
                . ' FROM \'' . $this->dbi->escapeString($username) . '\'@\''
                . $this->dbi->escapeString($hostname) . '\';';
        } else {
            $sql_query1 = '';
        }

        // Should not do a GRANT USAGE for a table-specific privilege, it
        // causes problems later (cannot revoke it)
        if (! (strlen($tablename) > 0
            && 'USAGE' == implode('', $this->extractPrivInfo()))
        ) {
            $sql_query2 = 'GRANT ' . implode(', ', $this->extractPrivInfo())
                . ' ON ' . $itemType . ' ' . $db_and_table
                . ' TO \'' . $this->dbi->escapeString($username) . '\'@\''
                . $this->dbi->escapeString($hostname) . '\'';

            if (strlen($dbname) === 0) {
                // add REQUIRE clause
                $sql_query2 .= $this->getRequireClause();
            }

            if ((isset($_POST['Grant_priv']) && $_POST['Grant_priv'] == 'Y')
                || (strlen($dbname) === 0
                && (isset($_POST['max_questions']) || isset($_POST['max_connections'])
                || isset($_POST['max_updates'])
                || isset($_POST['max_user_connections'])))
            ) {
                $sql_query2 .= $this->getWithClauseForAddUserAndUpdatePrivs();
            }
            $sql_query2 .= ';';
        }
        if (! $this->dbi->tryQuery($sql_query0)) {
            // This might fail when the executing user does not have
            // ALL PRIVILEGES himself.
            // See https://github.com/phpmyadmin/phpmyadmin/issues/9673
            $sql_query0 = '';
        }
        if (! empty($sql_query1) && ! $this->dbi->tryQuery($sql_query1)) {
            // this one may fail, too...
            $sql_query1 = '';
        }
        if (! empty($sql_query2)) {
            $this->dbi->query($sql_query2);
        } else {
            $sql_query2 = '';
        }
        $sql_query = $sql_query0 . ' ' . $sql_query1 . ' ' . $sql_query2;
        $message = Message::success(__('You have updated the privileges for %s.'));
        $message->addParam('\'' . $username . '\'@\'' . $hostname . '\'');

        return [
            $sql_query,
            $message,
        ];
    }

    /**
     * Get List of information: Changes / copies a user
     *
     * @return array
     */
    public function getDataForChangeOrCopyUser()
    {
        $queries = null;
        $password = null;

        if (isset($_POST['change_copy'])) {
            $user_host_condition = ' WHERE `User` = '
                . "'" . $this->dbi->escapeString($_POST['old_username']) . "'"
                . ' AND `Host` = '
                . "'" . $this->dbi->escapeString($_POST['old_hostname']) . "';";
            $row = $this->dbi->fetchSingleRow(
                'SELECT * FROM `mysql`.`user` ' . $user_host_condition
            );
            if (! $row) {
                $response = Response::getInstance();
                $response->addHTML(
                    Message::notice(__('No user found.'))->getDisplay()
                );
                unset($_POST['change_copy']);
            } else {
                foreach ($row as $key => $value) {
                    $GLOBALS[$key] = $value;
                }
                $serverVersion = $this->dbi->getVersion();
                // Recent MySQL versions have the field "Password" in mysql.user,
                // so the previous extract creates $row['Password'] but this script
                // uses $password
                if (! isset($row['password']) && isset($row['Password'])) {
                    $row['password'] = $row['Password'];
                }
                if (Util::getServerType() == 'MySQL'
                    && $serverVersion >= 50606
                    && $serverVersion < 50706
                    && ((isset($row['authentication_string'])
                    && empty($row['password']))
                    || (isset($row['plugin'])
                    && $row['plugin'] == 'sha256_password'))
                ) {
                    $row['password'] = $row['authentication_string'];
                }

                if (Util::getServerType() == 'MariaDB'
                    && $serverVersion >= 50500
                    && isset($row['authentication_string'])
                    && empty($row['password'])
                ) {
                    $row['password'] = $row['authentication_string'];
                }

                // Always use 'authentication_string' column
                // for MySQL 5.7.6+ since it does not have
                // the 'password' column at all
                if (in_array(Util::getServerType(), ['MySQL', 'Percona Server'])
                    && $serverVersion >= 50706
                    && isset($row['authentication_string'])
                ) {
                    $row['password'] = $row['authentication_string'];
                }
                $password = $row['password'];
                $queries = [];
            }
        }

        return [
            $queries,
            $password,
        ];
    }

    /**
     * Update Data for information: Deletes users
     *
     * @param array $queries queries array
     *
     * @return array
     */
    public function getDataForDeleteUsers($queries)
    {
        if (isset($_POST['change_copy'])) {
            $selected_usr = [
                $_POST['old_username'] . '&amp;#27;' . $_POST['old_hostname'],
            ];
        } else {
            $selected_usr = $_POST['selected_usr'];
            $queries = [];
        }

        // this happens, was seen in https://reports.phpmyadmin.net/reports/view/17146
        if (! is_array($selected_usr)) {
            return [];
        }

        foreach ($selected_usr as $each_user) {
            list($this_user, $this_host) = explode('&amp;#27;', $each_user);
            $queries[] = '# '
                . sprintf(
                    __('Deleting %s'),
                    '\'' . $this_user . '\'@\'' . $this_host . '\''
                )
                . ' ...';
            $queries[] = 'DROP USER \''
                . $this->dbi->escapeString($this_user)
                . '\'@\'' . $this->dbi->escapeString($this_host) . '\';';
            $this->relationCleanup->user($this_user);

            if (isset($_POST['drop_users_db'])) {
                $queries[] = 'DROP DATABASE IF EXISTS '
                    . Util::backquote($this_user) . ';';
                $GLOBALS['reload'] = true;
            }
        }
        return $queries;
    }

    /**
     * update Message For Reload
     *
     * @return Message|null
     */
    public function updateMessageForReload(): ?Message
    {
        $message = null;
        if (isset($_GET['flush_privileges'])) {
            $sql_query = 'FLUSH PRIVILEGES;';
            $this->dbi->query($sql_query);
            $message = Message::success(
                __('The privileges were reloaded successfully.')
            );
        }

        if (isset($_GET['validate_username'])) {
            $message = Message::success();
        }

        return $message;
    }

    /**
     * update Data For Queries from queries_for_display
     *
     * @param array      $queries             queries array
     * @param array|null $queries_for_display queries array for display
     *
     * @return array
     */
    public function getDataForQueries(array $queries, $queries_for_display)
    {
        $tmp_count = 0;
        foreach ($queries as $sql_query) {
            if ($sql_query[0] != '#') {
                $this->dbi->query($sql_query);
            }
            // when there is a query containing a hidden password, take it
            // instead of the real query sent
            if (isset($queries_for_display[$tmp_count])) {
                $queries[$tmp_count] = $queries_for_display[$tmp_count];
            }
            $tmp_count++;
        }

        return $queries;
    }

    /**
     * update Data for information: Adds a user
     *
     * @param string|array|null $dbname      db name
     * @param string            $username    user name
     * @param string            $hostname    host name
     * @param string|null       $password    password
     * @param bool              $is_menuwork is_menuwork set?
     *
     * @return array
     */
    public function addUser(
        $dbname,
        $username,
        $hostname,
        ?string $password,
        $is_menuwork
    ) {
        $_add_user_error = false;
        $message = null;
        $queries = null;
        $queries_for_display = null;
        $sql_query = null;

        if (! isset($_POST['adduser_submit']) && ! isset($_POST['change_copy'])) {
            return [
                $message,
                $queries,
                $queries_for_display,
                $sql_query,
                $_add_user_error,
            ];
        }

        $sql_query = '';
        if ($_POST['pred_username'] == 'any') {
            $username = '';
        }
        switch ($_POST['pred_hostname']) {
            case 'any':
                $hostname = '%';
                break;
            case 'localhost':
                $hostname = 'localhost';
                break;
            case 'hosttable':
                $hostname = '';
                break;
            case 'thishost':
                $_user_name = $this->dbi->fetchValue('SELECT USER()');
                $hostname = mb_substr(
                    $_user_name,
                    mb_strrpos($_user_name, '@') + 1
                );
                unset($_user_name);
                break;
        }
        $sql = "SELECT '1' FROM `mysql`.`user`"
            . " WHERE `User` = '" . $this->dbi->escapeString($username) . "'"
            . " AND `Host` = '" . $this->dbi->escapeString($hostname) . "';";
        if ($this->dbi->fetchValue($sql) == 1) {
            $message = Message::error(__('The user %s already exists!'));
            $message->addParam('[em]\'' . $username . '\'@\'' . $hostname . '\'[/em]');
            $_GET['adduser'] = true;
            $_add_user_error = true;

            return [
                $message,
                $queries,
                $queries_for_display,
                $sql_query,
                $_add_user_error,
            ];
        }

        list(
            $create_user_real,
            $create_user_show,
            $real_sql_query,
            $sql_query,
            $password_set_real,
            $password_set_show,
            $alter_real_sql_query,
            $alter_sql_query
        ) = $this->getSqlQueriesForDisplayAndAddUser(
            $username,
            $hostname,
            (isset($password) ? $password : '')
        );

        if (empty($_POST['change_copy'])) {
            $_error = false;

            if ($create_user_real !== null) {
                if (! $this->dbi->tryQuery($create_user_real)) {
                    $_error = true;
                }
                if (isset($password_set_real, $_POST['authentication_plugin']) && ! empty($password_set_real)) {
                    $this->setProperPasswordHashing(
                        $_POST['authentication_plugin']
                    );
                    if ($this->dbi->tryQuery($password_set_real)) {
                        $sql_query .= $password_set_show;
                    }
                }
                $sql_query = $create_user_show . $sql_query;
            }

            list($sql_query, $message) = $this->addUserAndCreateDatabase(
                $_error,
                $real_sql_query,
                $sql_query,
                $username,
                $hostname,
                $dbname,
                $alter_real_sql_query,
                $alter_sql_query
            );
            if (! empty($_POST['userGroup']) && $is_menuwork) {
                $this->setUserGroup($GLOBALS['username'], $_POST['userGroup']);
            }

            return [
                $message,
                $queries,
                $queries_for_display,
                $sql_query,
                $_add_user_error,
            ];
        }

        // Copy the user group while copying a user
        $old_usergroup =
            isset($_POST['old_usergroup']) ? $_POST['old_usergroup'] : null;
        $this->setUserGroup($_POST['username'], $old_usergroup);

        if ($create_user_real === null) {
            $queries[] = $create_user_real;
        }
        $queries[] = $real_sql_query;

        if (isset($password_set_real, $_POST['authentication_plugin']) && ! empty($password_set_real)) {
            $this->setProperPasswordHashing(
                $_POST['authentication_plugin']
            );

            $queries[] = $password_set_real;
        }
        // we put the query containing the hidden password in
        // $queries_for_display, at the same position occupied
        // by the real query in $queries
        $tmp_count = count($queries);
        if (isset($create_user_real)) {
            $queries_for_display[$tmp_count - 2] = $create_user_show;
        }
        if (isset($password_set_real) && ! empty($password_set_real)) {
            $queries_for_display[$tmp_count - 3] = $create_user_show;
            $queries_for_display[$tmp_count - 2] = $sql_query;
            $queries_for_display[$tmp_count - 1] = $password_set_show;
        } else {
            $queries_for_display[$tmp_count - 1] = $sql_query;
        }

        return [
            $message,
            $queries,
            $queries_for_display,
            $sql_query,
            $_add_user_error,
        ];
    }

    /**
     * Sets proper value of `old_passwords` according to
     * the authentication plugin selected
     *
     * @param string $auth_plugin authentication plugin selected
     *
     * @return void
     */
    public function setProperPasswordHashing($auth_plugin)
    {
        // Set the hashing method used by PASSWORD()
        // to be of type depending upon $authentication_plugin
        if ($auth_plugin == 'sha256_password') {
            $this->dbi->tryQuery('SET `old_passwords` = 2;');
        } elseif ($auth_plugin == 'mysql_old_password') {
            $this->dbi->tryQuery('SET `old_passwords` = 1;');
        } else {
            $this->dbi->tryQuery('SET `old_passwords` = 0;');
        }
    }

    /**
     * Update DB information: DB, Table, isWildcard
     *
     * @return array
     */
    public function getDataForDBInfo()
    {
        $username = null;
        $hostname = null;
        $dbname = null;
        $tablename = null;
        $routinename = null;
        $dbname_is_wildcard = null;

        if (isset($_REQUEST['username'])) {
            $username = $_REQUEST['username'];
        }
        if (isset($_REQUEST['hostname'])) {
            $hostname = $_REQUEST['hostname'];
        }
        /**
         * Checks if a dropdown box has been used for selecting a database / table
         */
        if (Core::isValid($_POST['pred_tablename'])) {
            $tablename = $_POST['pred_tablename'];
        } elseif (Core::isValid($_REQUEST['tablename'])) {
            $tablename = $_REQUEST['tablename'];
        } else {
            unset($tablename);
        }

        if (Core::isValid($_POST['pred_routinename'])) {
            $routinename = $_POST['pred_routinename'];
        } elseif (Core::isValid($_REQUEST['routinename'])) {
            $routinename = $_REQUEST['routinename'];
        } else {
            unset($routinename);
        }

        if (isset($_POST['pred_dbname'])) {
            $is_valid_pred_dbname = true;
            foreach ($_POST['pred_dbname'] as $key => $db_name) {
                if (! Core::isValid($db_name)) {
                    $is_valid_pred_dbname = false;
                    break;
                }
            }
        }

        if (isset($_REQUEST['dbname'])) {
            $is_valid_dbname = true;
            if (is_array($_REQUEST['dbname'])) {
                foreach ($_REQUEST['dbname'] as $key => $db_name) {
                    if (! Core::isValid($db_name)) {
                        $is_valid_dbname = false;
                        break;
                    }
                }
            } else {
                if (! Core::isValid($_REQUEST['dbname'])) {
                    $is_valid_dbname = false;
                }
            }
        }

        if (isset($is_valid_pred_dbname) && $is_valid_pred_dbname) {
            $dbname = $_POST['pred_dbname'];
            // If dbname contains only one database.
            if (count($dbname) === 1) {
                $dbname = $dbname[0];
            }
        } elseif (isset($is_valid_dbname) && $is_valid_dbname) {
            $dbname = $_REQUEST['dbname'];
        } else {
            unset($dbname, $tablename);
        }

        if (isset($dbname)) {
            if (is_array($dbname)) {
                $db_and_table = $dbname;
                foreach ($db_and_table as $key => $db_name) {
                    $db_and_table[$key] .= '.';
                }
            } else {
                $unescaped_db = Util::unescapeMysqlWildcards($dbname);
                $db_and_table = Util::backquote($unescaped_db) . '.';
            }
            if (isset($tablename)) {
                $db_and_table .= Util::backquote($tablename);
            } else {
                if (is_array($db_and_table)) {
                    foreach ($db_and_table as $key => $db_name) {
                        $db_and_table[$key] .= '*';
                    }
                } else {
                    $db_and_table .= '*';
                }
            }
        } else {
            $db_and_table = '*.*';
        }

        // check if given $dbname is a wildcard or not
        if (isset($dbname)) {
            //if (preg_match('/\\\\(?:_|%)/i', $dbname)) {
            if (! is_array($dbname) && preg_match('/(?<!\\\\)(?:_|%)/', $dbname)) {
                $dbname_is_wildcard = true;
            } else {
                $dbname_is_wildcard = false;
            }
        }

        return [
            $username,
            $hostname,
            isset($dbname) ? $dbname : null,
            isset($tablename) ? $tablename : null,
            isset($routinename) ? $routinename : null,
            $db_and_table,
            $dbname_is_wildcard,
        ];
    }

    /**
     * Get title and textarea for export user definition in Privileges
     *
     * @param string $username username
     * @param string $hostname host name
     *
     * @return array ($title, $export)
     */
    public function getListForExportUserDefinition($username, $hostname)
    {
        $export = '<textarea class="export" cols="60" rows="15">';

        if (isset($_POST['selected_usr'])) {
            // export privileges for selected users
            $title = __('Privileges');

            //For removing duplicate entries of users
            $_POST['selected_usr'] = array_unique($_POST['selected_usr']);

            foreach ($_POST['selected_usr'] as $export_user) {
                $export_username = mb_substr(
                    $export_user,
                    0,
                    mb_strpos($export_user, '&')
                );
                $export_hostname = mb_substr(
                    $export_user,
                    mb_strrpos($export_user, ';') + 1
                );
                $export .= '# '
                    . sprintf(
                        __('Privileges for %s'),
                        '`' . htmlspecialchars($export_username)
                        . '`@`' . htmlspecialchars($export_hostname) . '`'
                    )
                    . "\n\n";
                $export .= $this->getGrants($export_username, $export_hostname) . "\n";
            }
        } else {
            // export privileges for a single user
            $title = __('User') . ' `' . htmlspecialchars($username)
                . '`@`' . htmlspecialchars($hostname) . '`';
            $export .= $this->getGrants($username, $hostname);
        }
        // remove trailing whitespace
        $export = trim($export);

        $export .= '</textarea>';

        return [
            $title,
            $export,
        ];
    }

    /**
     * Get HTML for display Add userfieldset
     *
     * @param string $db    the database
     * @param string $table the table name
     *
     * @return string html output
     */
    public function getAddUserHtmlFieldset($db = '', $table = '')
    {
        if (! $GLOBALS['is_createuser']) {
            return '';
        }
        $rel_params = [];
        $url_params = [
            'adduser' => 1,
        ];
        if (! empty($db)) {
            $url_params['dbname']
                = $rel_params['checkprivsdb']
                    = $db;
        }
        if (! empty($table)) {
            $url_params['tablename']
                = $rel_params['checkprivstable']
                    = $table;
        }

        return $this->template->render('server/privileges/add_user_fieldset', [
            'url_params' => $url_params,
            'rel_params' => $rel_params,
        ]);
    }

    /**
     * Get HTML snippet for display user overview page
     *
     * @param string $pmaThemeImage a image source link
     * @param string $text_dir      text directory
     *
     * @return string
     */
    public function getHtmlForUserOverview($pmaThemeImage, $text_dir)
    {
        global $is_createuser;

        $password_column = 'Password';
        $server_type = Util::getServerType();
        $serverVersion = $this->dbi->getVersion();
        if (($server_type == 'MySQL' || $server_type == 'Percona Server')
            && $serverVersion >= 50706
        ) {
            $password_column = 'authentication_string';
        }
        // $sql_query is for the initial-filtered,
        // $sql_query_all is for counting the total no. of users

        $sql_query = $sql_query_all = 'SELECT *,' .
            " IF(`" . $password_column . "` = _latin1 '', 'N', 'Y') AS 'Password'" .
            ' FROM `mysql`.`user`';

        $sql_query .= (isset($_GET['initial'])
            ? $this->rangeOfUsers($_GET['initial'])
            : '');

        $sql_query .= ' ORDER BY `User` ASC, `Host` ASC;';
        $sql_query_all .= ' ;';

        $res = $this->dbi->tryQuery(
            $sql_query,
            DatabaseInterface::CONNECT_USER,
            DatabaseInterface::QUERY_STORE
        );
        $res_all = $this->dbi->tryQuery(
            $sql_query_all,
            DatabaseInterface::CONNECT_USER,
            DatabaseInterface::QUERY_STORE
        );

        $errorMessages = '';
        if (! $res) {
            // the query failed! This may have two reasons:
            // - the user does not have enough privileges
            // - the privilege tables use a structure of an earlier version.
            // so let's try a more simple query

            $this->dbi->freeResult($res);
            $this->dbi->freeResult($res_all);
            $sql_query = 'SELECT * FROM `mysql`.`user`';
            $res = $this->dbi->tryQuery(
                $sql_query,
                DatabaseInterface::CONNECT_USER,
                DatabaseInterface::QUERY_STORE
            );

            if (! $res) {
                $errorMessages .= $this->getHtmlForViewUsersError();
                $errorMessages .= $this->getAddUserHtmlFieldset();
            } else {
                // This message is hardcoded because I will replace it by
                // a automatic repair feature soon.
                $raw = 'Your privilege table structure seems to be older than'
                    . ' this MySQL version!<br>'
                    . 'Please run the <code>mysql_upgrade</code> command'
                    . ' that should be included in your MySQL server distribution'
                    . ' to solve this problem!';
                $errorMessages .= Message::rawError($raw)->getDisplay();
            }
            $this->dbi->freeResult($res);
        } else {
            $db_rights = $this->getDbRightsForUserOverview();
            // for all initials, even non A-Z
            $array_initials = [];

            foreach ($db_rights as $right) {
                foreach ($right as $account) {
                    if (empty($account['User']) && $account['Host'] == 'localhost') {
                        $emptyUserNotice = Message::notice(
                            __(
                                'A user account allowing any user from localhost to '
                                . 'connect is present. This will prevent other users '
                                . 'from connecting if the host part of their account '
                                . 'allows a connection from any (%) host.'
                            )
                            . Util::showMySQLDocu('problems-connecting')
                        )->getDisplay();
                        break 2;
                    }
                }
            }

            /**
             * Displays the initials
             * Also not necessary if there is less than 20 privileges
             */
            if ($this->dbi->numRows($res_all) > 20) {
                $initials = $this->getHtmlForInitials($array_initials);
            }

            /**
            * Display the user overview
            * (if less than 50 users, display them immediately)
            */
            if (isset($_GET['initial'])
                || isset($_GET['showall'])
                || $this->dbi->numRows($res) < 50
            ) {
                $usersOverview = $this->getUsersOverview(
                    $res,
                    $db_rights,
                    $pmaThemeImage,
                    $text_dir
                );
            }

            $response = Response::getInstance();
            if (! $response->isAjax()
                || ! empty($_REQUEST['ajax_page_request'])
            ) {
                if ($GLOBALS['is_reload_priv']) {
                    $flushnote = new Message(
                        __(
                            'Note: phpMyAdmin gets the users’ privileges directly '
                            . 'from MySQL’s privilege tables. The content of these '
                            . 'tables may differ from the privileges the server uses, '
                            . 'if they have been changed manually. In this case, '
                            . 'you should %sreload the privileges%s before you continue.'
                        ),
                        Message::NOTICE
                    );
                    $flushnote->addParamHtml(
                        '<a href="' . Url::getFromRoute('/server/privileges', ['flush_privileges' => 1])
                        . '" id="reload_privileges_anchor">'
                    );
                    $flushnote->addParamHtml('</a>');
                } else {
                    $flushnote = new Message(
                        __(
                            'Note: phpMyAdmin gets the users’ privileges directly '
                            . 'from MySQL’s privilege tables. The content of these '
                            . 'tables may differ from the privileges the server uses, '
                            . 'if they have been changed manually. In this case, '
                            . 'the privileges have to be reloaded but currently, you '
                            . 'don\'t have the RELOAD privilege.'
                        )
                        . Util::showMySQLDocu(
                            'privileges-provided',
                            false,
                            null,
                            null,
                            'priv_reload'
                        ),
                        Message::NOTICE
                    );
                }
                $flushNotice = $flushnote->getDisplay();
            }
        }

        return $this->template->render('server/privileges/user_overview', [
            'error_messages' => $errorMessages,
            'empty_user_notice' => $emptyUserNotice ?? '',
            'initials' => $initials ?? '',
            'users_overview' => $usersOverview ?? '',
            'is_createuser' => $is_createuser,
            'flush_notice' => $flushNotice ?? '',
        ]);
    }

    /**
     * Get HTML snippet for display user properties
     *
     * @param boolean      $dbname_is_wildcard whether database name is wildcard or not
     * @param string       $url_dbname         url database name that urlencode() string
     * @param string       $username           username
     * @param string       $hostname           host name
     * @param string|array $dbname             database name
     * @param string       $tablename          table name
     *
     * @return string
     */
    public function getHtmlForUserProperties(
        $dbname_is_wildcard,
        $url_dbname,
        $username,
        $hostname,
        $dbname,
        $tablename
    ) {
        $sql = "SELECT '1' FROM `mysql`.`user`"
            . " WHERE `User` = '" . $this->dbi->escapeString($username) . "'"
            . " AND `Host` = '" . $this->dbi->escapeString($hostname) . "';";

        $user_does_not_exists = (bool) ! $this->dbi->fetchValue($sql);

        $loginInformationFields = '';
        if ($user_does_not_exists) {
            $loginInformationFields = $this->getHtmlForLoginInformationFields();
        }

        $_params = [
            'username' => $username,
            'hostname' => $hostname,
        ];
        if (! is_array($dbname) && strlen($dbname) > 0) {
            $_params['dbname'] = $dbname;
            if (strlen($tablename) > 0) {
                $_params['tablename'] = $tablename;
            }
        } else {
            $_params['dbname'] = $dbname;
        }

        $privilegesTable = $this->getHtmlToDisplayPrivilegesTable(
            // If $dbname is an array, pass any one db as all have same privs.
            Core::ifSetOr($dbname, is_array($dbname) ? $dbname[0] : '*', 'length'),
            Core::ifSetOr($tablename, '*', 'length')
        );

        $tableSpecificRights = '';
        if (! is_array($dbname) && strlen($tablename) === 0
            && empty($dbname_is_wildcard)
        ) {
            // no table name was given, display all table specific rights
            // but only if $dbname contains no wildcards
            if (strlen($dbname) === 0) {
                $tableSpecificRights .= $this->getHtmlForAllTableSpecificRights(
                    $username,
                    $hostname,
                    'database'
                );
            } else {
                // unescape wildcards in dbname at table level
                $unescaped_db = Util::unescapeMysqlWildcards($dbname);

                $tableSpecificRights .= $this->getHtmlForAllTableSpecificRights(
                    $username,
                    $hostname,
                    'table',
                    $unescaped_db
                );
                $tableSpecificRights .= $this->getHtmlForAllTableSpecificRights(
                    $username,
                    $hostname,
                    'routine',
                    $unescaped_db
                );
            }
        }

        // Provide a line with links to the relevant database and table
        $linkToDatabaseAndTable = '';
        if (! is_array($dbname) && strlen($dbname) > 0 && empty($dbname_is_wildcard)) {
            $linkToDatabaseAndTable = $this->getLinkToDbAndTable($url_dbname, $dbname, $tablename);
        }

        $changePassword = '';
        $changeLoginInformation = '';
        if (! is_array($dbname) && strlen($dbname) === 0 && ! $user_does_not_exists) {
            //change login information
            $changePassword = ChangePassword::getHtml(
                'edit_other',
                $username,
                $hostname
            );
            $changeLoginInformation = $this->getChangeLoginInformationHtmlForm($username, $hostname);
        }

        return $this->template->render('server/privileges/user_properties', [
            'user_does_not_exists' => $user_does_not_exists,
            'login_information_fields' => $loginInformationFields,
            'params' => $_params,
            'privileges_table' => $privilegesTable,
            'table_specific_rights' => $tableSpecificRights,
            'link_to_database_and_table' => $linkToDatabaseAndTable,
            'change_password' => $changePassword,
            'change_login_information' => $changeLoginInformation,
            'database' => $dbname,
            'dbname' => $url_dbname,
            'username' => $username,
            'hostname' => $hostname,
            'is_databases' => $dbname_is_wildcard || is_array($dbname) && count($dbname) > 1,
            'table' => $tablename,
            'current_user' => $this->dbi->getCurrentUser(),
        ]);
    }

    /**
     * Get queries for Table privileges to change or copy user
     *
     * @param string $user_host_condition user host condition to
     *                                    select relevant table privileges
     * @param array  $queries             queries array
     * @param string $username            username
     * @param string $hostname            host name
     *
     * @return array
     */
    public function getTablePrivsQueriesForChangeOrCopyUser(
        $user_host_condition,
        array $queries,
        $username,
        $hostname
    ) {
        $res = $this->dbi->query(
            'SELECT `Db`, `Table_name`, `Table_priv` FROM `mysql`.`tables_priv`'
            . $user_host_condition,
            DatabaseInterface::CONNECT_USER,
            DatabaseInterface::QUERY_STORE
        );
        while ($row = $this->dbi->fetchAssoc($res)) {
            $res2 = $this->dbi->query(
                'SELECT `Column_name`, `Column_priv`'
                . ' FROM `mysql`.`columns_priv`'
                . ' WHERE `User`'
                . ' = \'' . $this->dbi->escapeString($_POST['old_username']) . "'"
                . ' AND `Host`'
                . ' = \'' . $this->dbi->escapeString($_POST['old_username']) . '\''
                . ' AND `Db`'
                . ' = \'' . $this->dbi->escapeString($row['Db']) . "'"
                . ' AND `Table_name`'
                . ' = \'' . $this->dbi->escapeString($row['Table_name']) . "'"
                . ';',
                DatabaseInterface::CONNECT_USER,
                DatabaseInterface::QUERY_STORE
            );

            $tmp_privs1 = $this->extractPrivInfo($row);
            $tmp_privs2 = [
                'Select' => [],
                'Insert' => [],
                'Update' => [],
                'References' => [],
            ];

            while ($row2 = $this->dbi->fetchAssoc($res2)) {
                $tmp_array = explode(',', $row2['Column_priv']);
                if (in_array('Select', $tmp_array)) {
                    $tmp_privs2['Select'][] = $row2['Column_name'];
                }
                if (in_array('Insert', $tmp_array)) {
                    $tmp_privs2['Insert'][] = $row2['Column_name'];
                }
                if (in_array('Update', $tmp_array)) {
                    $tmp_privs2['Update'][] = $row2['Column_name'];
                }
                if (in_array('References', $tmp_array)) {
                    $tmp_privs2['References'][] = $row2['Column_name'];
                }
            }
            if (count($tmp_privs2['Select']) > 0 && ! in_array('SELECT', $tmp_privs1)) {
                $tmp_privs1[] = 'SELECT (`' . implode('`, `', $tmp_privs2['Select']) . '`)';
            }
            if (count($tmp_privs2['Insert']) > 0 && ! in_array('INSERT', $tmp_privs1)) {
                $tmp_privs1[] = 'INSERT (`' . implode('`, `', $tmp_privs2['Insert']) . '`)';
            }
            if (count($tmp_privs2['Update']) > 0 && ! in_array('UPDATE', $tmp_privs1)) {
                $tmp_privs1[] = 'UPDATE (`' . implode('`, `', $tmp_privs2['Update']) . '`)';
            }
            if (count($tmp_privs2['References']) > 0
                && ! in_array('REFERENCES', $tmp_privs1)
            ) {
                $tmp_privs1[]
                    = 'REFERENCES (`' . implode('`, `', $tmp_privs2['References']) . '`)';
            }

            $queries[] = 'GRANT ' . implode(', ', $tmp_privs1)
                . ' ON ' . Util::backquote($row['Db']) . '.'
                . Util::backquote($row['Table_name'])
                . ' TO \'' . $this->dbi->escapeString($username)
                . '\'@\'' . $this->dbi->escapeString($hostname) . '\''
                . (in_array('Grant', explode(',', $row['Table_priv']))
                ? ' WITH GRANT OPTION;'
                : ';');
        }
        return $queries;
    }

    /**
     * Get queries for database specific privileges for change or copy user
     *
     * @param array  $queries  queries array with string
     * @param string $username username
     * @param string $hostname host name
     *
     * @return array
     */
    public function getDbSpecificPrivsQueriesForChangeOrCopyUser(
        array $queries,
        $username,
        $hostname
    ) {
        $user_host_condition = ' WHERE `User`'
            . ' = \'' . $this->dbi->escapeString($_POST['old_username']) . "'"
            . ' AND `Host`'
            . ' = \'' . $this->dbi->escapeString($_POST['old_hostname']) . '\';';

        $res = $this->dbi->query(
            'SELECT * FROM `mysql`.`db`' . $user_host_condition
        );

        while ($row = $this->dbi->fetchAssoc($res)) {
            $queries[] = 'GRANT ' . implode(', ', $this->extractPrivInfo($row))
                . ' ON ' . Util::backquote($row['Db']) . '.*'
                . ' TO \'' . $this->dbi->escapeString($username)
                . '\'@\'' . $this->dbi->escapeString($hostname) . '\''
                . ($row['Grant_priv'] == 'Y' ? ' WITH GRANT OPTION;' : ';');
        }
        $this->dbi->freeResult($res);

        $queries = $this->getTablePrivsQueriesForChangeOrCopyUser(
            $user_host_condition,
            $queries,
            $username,
            $hostname
        );

        return $queries;
    }

    /**
     * Prepares queries for adding users and
     * also create database and return query and message
     *
     * @param boolean $_error               whether user create or not
     * @param string  $real_sql_query       SQL query for add a user
     * @param string  $sql_query            SQL query to be displayed
     * @param string  $username             username
     * @param string  $hostname             host name
     * @param string  $dbname               database name
     * @param string  $alter_real_sql_query SQL query for ALTER USER
     * @param string  $alter_sql_query      SQL query for ALTER USER to be displayed
     *
     * @return array, $message
     */
    public function addUserAndCreateDatabase(
        $_error,
        $real_sql_query,
        $sql_query,
        $username,
        $hostname,
        $dbname,
        $alter_real_sql_query,
        $alter_sql_query
    ) {
        if ($_error || (! empty($real_sql_query)
            && ! $this->dbi->tryQuery($real_sql_query))
        ) {
            $_POST['createdb-1'] = $_POST['createdb-2']
                = $_POST['createdb-3'] = null;
            $message = Message::rawError($this->dbi->getError());
        } elseif ($alter_real_sql_query !== '' && ! $this->dbi->tryQuery($alter_real_sql_query)) {
            $_POST['createdb-1'] = $_POST['createdb-2']
                = $_POST['createdb-3'] = null;
            $message = Message::rawError($this->dbi->getError());
        } else {
            $sql_query .= $alter_sql_query;
            $message = Message::success(__('You have added a new user.'));
        }

        if (isset($_POST['createdb-1'])) {
            // Create database with same name and grant all privileges
            $q = 'CREATE DATABASE IF NOT EXISTS '
                . Util::backquote(
                    $this->dbi->escapeString($username)
                ) . ';';
            $sql_query .= $q;
            if (! $this->dbi->tryQuery($q)) {
                $message = Message::rawError($this->dbi->getError());
            }

            /**
             * Reload the navigation
             */
            $GLOBALS['reload'] = true;
            $GLOBALS['db'] = $username;

            $q = 'GRANT ALL PRIVILEGES ON '
                . Util::backquote(
                    Util::escapeMysqlWildcards(
                        $this->dbi->escapeString($username)
                    )
                ) . '.* TO \''
                . $this->dbi->escapeString($username)
                . '\'@\'' . $this->dbi->escapeString($hostname) . '\';';
            $sql_query .= $q;
            if (! $this->dbi->tryQuery($q)) {
                $message = Message::rawError($this->dbi->getError());
            }
        }

        if (isset($_POST['createdb-2'])) {
            // Grant all privileges on wildcard name (username\_%)
            $q = 'GRANT ALL PRIVILEGES ON '
                . Util::backquote(
                    Util::escapeMysqlWildcards(
                        $this->dbi->escapeString($username)
                    ) . '\_%'
                ) . '.* TO \''
                . $this->dbi->escapeString($username)
                . '\'@\'' . $this->dbi->escapeString($hostname) . '\';';
            $sql_query .= $q;
            if (! $this->dbi->tryQuery($q)) {
                $message = Message::rawError($this->dbi->getError());
            }
        }

        if (isset($_POST['createdb-3'])) {
            // Grant all privileges on the specified database to the new user
            $q = 'GRANT ALL PRIVILEGES ON '
            . Util::backquote(
                $this->dbi->escapeString($dbname)
            ) . '.* TO \''
            . $this->dbi->escapeString($username)
            . '\'@\'' . $this->dbi->escapeString($hostname) . '\';';
            $sql_query .= $q;
            if (! $this->dbi->tryQuery($q)) {
                $message = Message::rawError($this->dbi->getError());
            }
        }
        return [
            $sql_query,
            $message,
        ];
    }

    /**
     * Get the hashed string for password
     *
     * @param string $password password
     *
     * @return string
     */
    public function getHashedPassword($password)
    {
        $password = $this->dbi->escapeString($password);
        $result = $this->dbi->fetchSingleRow(
            "SELECT PASSWORD('" . $password . "') AS `password`;"
        );

        return $result['password'];
    }

    /**
     * Check if MariaDB's 'simple_password_check'
     * OR 'cracklib_password_check' is ACTIVE
     *
     * @return boolean if atleast one of the plugins is ACTIVE
     */
    public function checkIfMariaDBPwdCheckPluginActive()
    {
        $serverVersion = $this->dbi->getVersion();
        if (! (Util::getServerType() == 'MariaDB' && $serverVersion >= 100002)) {
            return false;
        }

        $result = $this->dbi->tryQuery(
            'SHOW PLUGINS SONAME LIKE \'%_password_check%\''
        );

        /* Plugins are not working, for example directory does not exists */
        if ($result === false) {
            return false;
        }

        while ($row = $this->dbi->fetchAssoc($result)) {
            if ($row['Status'] === 'ACTIVE') {
                return true;
            }
        }

        return false;
    }


    /**
     * Get SQL queries for Display and Add user
     *
     * @param string $username username
     * @param string $hostname host name
     * @param string $password password
     *
     * @return array ($create_user_real, $create_user_show, $real_sql_query, $sql_query
     *                $password_set_real, $password_set_show, $alter_real_sql_query, $alter_sql_query)
     */
    public function getSqlQueriesForDisplayAndAddUser($username, $hostname, $password)
    {
        $slashedUsername = $this->dbi->escapeString($username);
        $slashedHostname = $this->dbi->escapeString($hostname);
        $slashedPassword = $this->dbi->escapeString($password);
        $serverType = Util::getServerType();
        $serverVersion = $this->dbi->getVersion();

        $create_user_stmt = sprintf(
            'CREATE USER \'%s\'@\'%s\'',
            $slashedUsername,
            $slashedHostname
        );
        $isMariaDBPwdPluginActive = $this->checkIfMariaDBPwdCheckPluginActive();

        // See https://github.com/phpmyadmin/phpmyadmin/pull/11560#issuecomment-147158219
        // for details regarding details of syntax usage for various versions

        // 'IDENTIFIED WITH auth_plugin'
        // is supported by MySQL 5.5.7+
        if (($serverType == 'MySQL' || $serverType == 'Percona Server')
            && $serverVersion >= 50507
            && isset($_POST['authentication_plugin'])
        ) {
            $create_user_stmt .= ' IDENTIFIED WITH '
                . $_POST['authentication_plugin'];
        }

        // 'IDENTIFIED VIA auth_plugin'
        // is supported by MariaDB 5.2+
        if ($serverType == 'MariaDB'
            && $serverVersion >= 50200
            && isset($_POST['authentication_plugin'])
            && ! $isMariaDBPwdPluginActive
        ) {
            $create_user_stmt .= ' IDENTIFIED VIA '
                . $_POST['authentication_plugin'];
        }

        $create_user_real = $create_user_stmt;
        $create_user_show = $create_user_stmt;

        $password_set_stmt = 'SET PASSWORD FOR \'%s\'@\'%s\' = \'%s\'';
        $password_set_show = sprintf(
            $password_set_stmt,
            $slashedUsername,
            $slashedHostname,
            '***'
        );

        $sql_query_stmt = sprintf(
            'GRANT %s ON *.* TO \'%s\'@\'%s\'',
            implode(', ', $this->extractPrivInfo()),
            $slashedUsername,
            $slashedHostname
        );
        $real_sql_query = $sql_query = $sql_query_stmt;

        // Set the proper hashing method
        if (isset($_POST['authentication_plugin'])) {
            $this->setProperPasswordHashing(
                $_POST['authentication_plugin']
            );
        }

        // Use 'CREATE USER ... WITH ... AS ..' syntax for
        // newer MySQL versions
        // and 'CREATE USER ... VIA .. USING ..' syntax for
        // newer MariaDB versions
        if ((($serverType == 'MySQL' || $serverType == 'Percona Server')
            && $serverVersion >= 50706)
            || ($serverType == 'MariaDB'
            && $serverVersion >= 50200)
        ) {
            $password_set_real = null;

            // Required for binding '%' with '%s'
            $create_user_stmt = str_replace(
                '%',
                '%%',
                $create_user_stmt
            );

            // MariaDB uses 'USING' whereas MySQL uses 'AS'
            // but MariaDB with validation plugin needs cleartext password
            if ($serverType == 'MariaDB'
                && ! $isMariaDBPwdPluginActive
            ) {
                $create_user_stmt .= ' USING \'%s\'';
            } elseif ($serverType == 'MariaDB') {
                $create_user_stmt .= ' IDENTIFIED BY \'%s\'';
            } elseif (($serverType == 'MySQL' || $serverType == 'Percona Server') && $serverVersion >= 80011) {
                $create_user_stmt .= ' BY \'%s\'';
            } else {
                $create_user_stmt .= ' AS \'%s\'';
            }

            if ($_POST['pred_password'] == 'keep') {
                $create_user_real = sprintf(
                    $create_user_stmt,
                    $slashedPassword
                );
                $create_user_show = sprintf(
                    $create_user_stmt,
                    '***'
                );
            } elseif ($_POST['pred_password'] == 'none') {
                $create_user_real = sprintf(
                    $create_user_stmt,
                    null
                );
                $create_user_show = sprintf(
                    $create_user_stmt,
                    '***'
                );
            } else {
                if (! (($serverType == 'MariaDB' && $isMariaDBPwdPluginActive)
                    || ($serverType == 'MySQL' || $serverType == 'Percona Server') && $serverVersion >= 80011)) {
                    $hashedPassword = $this->getHashedPassword($_POST['pma_pw']);
                } else {
                    // MariaDB with validation plugin needs cleartext password
                    $hashedPassword = $_POST['pma_pw'];
                }
                $create_user_real = sprintf(
                    $create_user_stmt,
                    $hashedPassword
                );
                $create_user_show = sprintf(
                    $create_user_stmt,
                    '***'
                );
            }
        } else {
            // Use 'SET PASSWORD' syntax for pre-5.7.6 MySQL versions
            // and pre-5.2.0 MariaDB versions
            if ($_POST['pred_password'] == 'keep') {
                $password_set_real = sprintf(
                    $password_set_stmt,
                    $slashedUsername,
                    $slashedHostname,
                    $slashedPassword
                );
            } elseif ($_POST['pred_password'] == 'none') {
                $password_set_real = sprintf(
                    $password_set_stmt,
                    $slashedUsername,
                    $slashedHostname,
                    null
                );
            } else {
                $hashedPassword = $this->getHashedPassword($_POST['pma_pw']);
                $password_set_real = sprintf(
                    $password_set_stmt,
                    $slashedUsername,
                    $slashedHostname,
                    $hashedPassword
                );
            }
        }

        $alter_real_sql_query = '';
        $alter_sql_query = '';
        if (($serverType == 'MySQL' || $serverType == 'Percona Server') && $serverVersion >= 80011) {
            $sql_query_stmt = '';
            if ((isset($_POST['Grant_priv']) && $_POST['Grant_priv'] == 'Y')
                || (isset($GLOBALS['Grant_priv']) && $GLOBALS['Grant_priv'] == 'Y')
            ) {
                $sql_query_stmt = ' WITH GRANT OPTION';
            }
            $real_sql_query .= $sql_query_stmt;
            $sql_query .= $sql_query_stmt;

            $alter_sql_query_stmt = sprintf(
                'ALTER USER \'%s\'@\'%s\'',
                $slashedUsername,
                $slashedHostname
            );
            $alter_real_sql_query = $alter_sql_query_stmt;
            $alter_sql_query = $alter_sql_query_stmt;
        }

        // add REQUIRE clause
        $require_clause = $this->getRequireClause();
        $with_clause = $this->getWithClauseForAddUserAndUpdatePrivs();

        if (($serverType == 'MySQL' || $serverType == 'Percona Server') && $serverVersion >= 80011) {
            $alter_real_sql_query .= $require_clause;
            $alter_sql_query .= $require_clause;
            $alter_real_sql_query .= $with_clause;
            $alter_sql_query .= $with_clause;
        } else {
            $real_sql_query .= $require_clause;
            $sql_query .= $require_clause;
            $real_sql_query .= $with_clause;
            $sql_query .= $with_clause;
        }

        if ($alter_real_sql_query !== '') {
            $alter_real_sql_query .= ';';
            $alter_sql_query .= ';';
        }
        $create_user_real .= ';';
        $create_user_show .= ';';
        $real_sql_query .= ';';
        $sql_query .= ';';
        // No Global GRANT_OPTION privilege
        if (! $GLOBALS['is_grantuser']) {
            $real_sql_query = '';
            $sql_query = '';
        }

        // Use 'SET PASSWORD' for pre-5.7.6 MySQL versions
        // and pre-5.2.0 MariaDB
        if (($serverType == 'MySQL'
            && $serverVersion >= 50706)
            || ($serverType == 'MariaDB'
            && $serverVersion >= 50200)
        ) {
            $password_set_real = null;
            $password_set_show = null;
        } else {
            if ($password_set_real !== null) {
                $password_set_real .= ";";
            }
            $password_set_show .= ";";
        }

        return [
            $create_user_real,
            $create_user_show,
            $real_sql_query,
            $sql_query,
            $password_set_real,
            $password_set_show,
            $alter_real_sql_query,
            $alter_sql_query,
        ];
    }

    /**
     * Returns the type ('PROCEDURE' or 'FUNCTION') of the routine
     *
     * @param string $dbname      database
     * @param string $routineName routine
     *
     * @return string type
     */
    public function getRoutineType($dbname, $routineName)
    {
        $routineData = $this->dbi->getRoutines($dbname);

        foreach ($routineData as $routine) {
            if ($routine['name'] === $routineName) {
                return $routine['type'];
            }
        }
        return '';
    }

    /**
     * @param string $username User name
     * @param string $hostname Host name
     * @param string $database Database name
     * @param string $routine  Routine name
     *
     * @return array
     */
    private function getRoutinePrivileges(
        string $username,
        string $hostname,
        string $database,
        string $routine
    ): array {
        $sql = "SELECT `Proc_priv`"
            . " FROM `mysql`.`procs_priv`"
            . " WHERE `User` = '" . $this->dbi->escapeString($username) . "'"
            . " AND `Host` = '" . $this->dbi->escapeString($hostname) . "'"
            . " AND `Db` = '"
            . $this->dbi->escapeString(Util::unescapeMysqlWildcards($database)) . "'"
            . " AND `Routine_name` LIKE '" . $this->dbi->escapeString($routine) . "';";
        $privileges = $this->dbi->fetchValue($sql);
        if ($privileges === false) {
            $privileges = '';
        }
        return $this->parseProcPriv($privileges);
    }
}
