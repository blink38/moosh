<?php
/**
 * moosh - Moodle Shell
 *
 * @copyright  2012 onwards Tomasz Muras
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace Moosh\Command\Moodle39\Category;

use Moosh\MooshCommand;
use restore_controller;
use restore_dbops;
use backup;

class CategoryDuplicate extends MooshCommand {

    private $debug = false;

    private $prefixe = "";


    public function __construct() {
        parent::__construct('duplicate', 'category');

        //$this->addArgument('course_name');
        $this->addArgument('categoryid');
        $this->addArgument('course_prefix_shortname');

        $this->addOption('p|parent:', 'category in which duplicating will be done');

        $this->addOption('d|debug', 'enable debug output');

        //$this->addOption('d|directory', 'restore from extracted directory (1st param) under tempdir/backup');
        //$this->addOption('e|existing', 'restore into existing course, id provided instead of category_id');
        //$this->addOption('o|overwrite', 'restore into existing course, overwrite current content, id provided instead of category_id');

        $this->minArguments = 0;
        $this->maxArguments = 255;
    }
    //$this->addOption('i|ignore-warnings', 'continue with restore if there are pre-check warnings');

    public function execute() {
        global $CFG, $DB, $USER;

        $catid = $this->arguments[0];
        $this->prefix = $this->arguments[1];

        foreach ($this->arguments as $argument) {
            $this->expandOptionsManually(array($argument));
        }

        $this->expandOptions();
        
        $options = $this->expandedOptions;

        $this->debug = $options['debug'];
        $this->verbose("Category to duplicate : " . $catid);

        require_once $CFG->dirroot . '/course/lib.php';

        $category = $this->assertCategoryExist($catid);

        $parentid = empty($options['parent']) ? $category->parent : $options['parent'];

        $parent = $this->assertCategoryExist($parentid);

        $this->debug("Will duplicate category into " . $parent->id);

        $this->duplicate_category($category, $parent);

        return 0;
    }

    /**
     * Duplicate category structure into another one
     * @param string $category category to duplicate
     * @param string $parent category into which duplication will be done
     * 
     */

    private function duplicate_category(\core_course_category $category, \core_course_category $parent){

        $this->debug("call duplicate_category(".$category->id.",".$parent->id.")");
        global $DB;

        // find all children of the category, not only directly child.
        // so, we will have to filter on parent id
        $categories = $category->get_all_children_ids();
        
        $this->debug("  found " . count($categories) . " children");

        foreach ($categories as $id){

            $cat = \core_course_category::get($id, IGNORE_MISSING);

            
            if (!empty($cat) && $cat->parent == $category->id){

                $existing = $DB->get_record('course_categories',
                                                        array( 'name' => $cat->name,
                                                            'parent' => $parent->id));
                                                            
                if (empty($existing)){
                    
                    $newcat = $this->clone_category($cat);
                    $newcat->parent = $parent->id;

                    $new = \core_course_category::create($newcat);

                    $this->debug("    duplicate " .$cat->name . "(" . $id. ") =>  created " .$new->id);
                } else {

                    $new = \core_course_category::get($existing->id);
                    $this->debug("    duplicate " .$cat->name . "(" . $id. ") => already exists ". $existing->id);
                }
               
                $this->duplicate_courses($cat, $new);

                $this->duplicate_category($cat, $new);
            }
        }
    }

    /**
     * Duplicate courses in category
     * 
     * @param \core_course_category $cat category containing course to duplicate
     * @param \core_course_category $category category in which new course will be duplicated
     */
    private function duplicate_courses(\core_course_category $cat, \core_course_category $category){

        $courses_to_duplicate = $cat->get_courses();

        $courses = $category->get_courses();

        foreach ($courses_to_duplicate as $course){

            // search existing course
            $found = false;
            foreach ($courses as $c){

                if ($c->shortname == $this->prefixed_shortname($course->shortname)){
                    $found = true;
                break;
                }
            }

            if ($found){
                $this->debug(" + already existing duplicated course for " . $course->shortname . "(" . $course->id . ")");

            } else {
                $this->debug(" + course do not exist. Duplicate it. ". $course->shortname . "(" . $course->id . ")");

                $clone = $this->clone_course_into_category($course, $category);

                if (!$this->duplicate_course_content($course, $clone, $category)){
                    echo " course content duplicate failed : from " . $course->id . " to " . $clone->id . PHP_EOL;
                }
            }

        }
    }

    /**
     * Clone a course into a category
     * 
     * @param $course course to clone
     * @param $category category in which the cloned course will be put
     * @return object course created
     */
    private function clone_course_into_category($course, $category) : ?object 
    {

        $newcourse = new \stdClass();
        $newcourse->fullname = $course->fullname;
        $newcourse->shortname = $this->prefixed_shortname($course->shortname);
        $newcourse->format = $course->format;

        $newcourse->idnumber = $course->idnumber;
        $newcourse->visible = $course->visible;
        $newcourse->category = $category->id;
        $newcourse->summary = $course->summary;
        $newcourse->summaryformat = $course->summaryformat;
        $newcourse->startdate = time();

        $created = create_course($newcourse);

        $this->debug("course created : " . $created->id);
        return $created;
    }

    /**
     * Return the prefixed shortname of a course
     * The prefix is used to avoid course having same shortname as the course it is duplicated from.
     * 
     * @param string $name shortname of the original course
     * @return string shortname prefixed
     */
    private function prefixed_shortname(string $name) :string
    {
        return $this->prefix . ' ' . $name;
    }

    /**
     * Duplicate a course
     * 
     * @param $course course to duplicate
     * @param $clone course duplicated
     * @param \core_course\category $category category into which create the duplicated course
     */
    private function duplicate_course_content($course, $clone, \core_course_category $category){

        global $CFG, $DB;

        try {

            require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
            require_once($CFG->dirroot . '/backup/controller/backup_controller.class.php');
            require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');

            $params = array( 'shortname' => $clone->shortname,
                             'fullname' => $clone->fullname,
                             'visible' => $clone->visible);


            $admin = get_admin();

            $options = array(
                'activities' => 1,
                'blocks' => 1,
                'filters' => 1,
                'users' => 1,
                'role_assignments' => 1,
                'comments' => 0,
                'logs' => 0,
            );

            ///////////////////////////////////////////////////////////////////////////////
            /// Backup $course
            ///////////////////////////////////////////////////////////////////////////////

            $this->debug("    - starting course backup : " . $course->shortname . " (" . $course->id . ")");

            $bc = new \backup_controller(\backup::TYPE_1COURSE, $course->id,
                \backup::FORMAT_MOODLE, \backup::INTERACTIVE_NO, \backup::MODE_SAMESITE, $admin->id);

            foreach ($options as $name => $value) {
                if ($setting = $bc->get_plan()->get_setting($name)) {
                    $bc->get_plan()->get_setting($name)->set_value($value);
                }
            }

            $outcome = $bc->execute_plan();
            $results = $bc->get_results();
            $file = $results['backup_destination'];

            $backupdir = basename($bc->get_plan()->get_basepath());
            $bc->destroy();
            unset($bc);

            $this->debug("    - course backup ended : " . $course->shortname . " (" . $course->id . ")");
            

            ///////////////////////////////////////////////////////////////////////////////
            /// Restore to $clone
            ///////////////////////////////////////////////////////////////////////////////
            
            $this->debug("    - starting course restore : " . $clone->shortname . " (" . $clone->id . ")");

            if (!file_exists($CFG->dataroot.'/temp/backup/'.$backupdir . "/moodle_backup.xml")) {
                $file->extract_to_pathname(get_file_packer('application/vnd.moodle.backup'), $CFG->dataroot.'/temp/backup/'.$backupdir);
            }

            if (file_exists($CFG->dataroot.'/temp/backup/'.$backupdir.'/course/course.xml')) {
                $controller = new \restore_controller($backupdir,
                                                    $clone->id,
                                                    \backup::INTERACTIVE_NO,
                                                    \backup::MODE_SAMESITE,
                                                    $admin->id,
                                                    \backup::TARGET_NEW_COURSE);

                foreach ($options as $name => $value) {
                    $setting = $controller->get_plan()->get_setting($name);
                    if ($setting->get_status() == \backup_setting::NOT_LOCKED) {
                        $setting->set_value($value);
                    }
                }

                if (!$controller->execute_precheck()) {
                    if ($controller->get_status() !== \backup::STATUS_AWAITING) {
                        die;
                    }
                }

                $controller->execute_plan();
                rebuild_course_cache($clone->id);
                $file->delete();

                // need to update course fullname, shortname and visibility, modified by restore
                $course = $DB->get_record('course', array('id' => $clone->id), '*', MUST_EXIST);
                $course->fullname = $params['fullname'];
                $course->shortname = $params['shortname'];
                $course->visible = $params['visible'];

                // Set shortname and fullname back.
                $DB->update_record('course', $course);

                $this->debug("    backup restore to " . $clone->shortname . " (" . $clone->id . ")");
            } else {
                $this->debug("    backup not found... !?");
            }
        
        } catch (\Exception $e){

            $this->debug($e->getMessage());
            return false;

        }
        return true;
    }

    /**
     * Clone a category into new object
     * 
     * @param \core_course_category $categorie category to clone
     * @return object the category cloned.
     */
    private function clone_category(\core_course_category $categorie) : object
    {
        // cf course/classes/category.php function create()

        $clone = new \stdClass();
        $clone->description = $categorie->description;
        $clone->descriptionformat = $categorie->descriptionformat;
        
         // Copy all description* fields regardless of whether this is form data or direct field update.
        foreach ($categorie as $key => $value) {
            if (preg_match("/^description/", $key)) {
                $clone->$key = $value;
            }
        }

        $clone->name = $categorie->name;
        $clone->idnumber = empty($categorie->idnumber) ? null : $categorie->idnumber . "_" . uniqid();

        $clone->theme = $categorie->theme;
        $clone->visible = $categorie->visible;

        return $clone;
    }

    /**
     * Check if category exist
     * Return the category found if exist, else exit (-1).
     * @param string $id category id
     * @param bool $exit do exit or return null
     * @return \core_course_category the category found
     */
    private function assertCategoryExist(string $id, bool $exit = true) : ?\core_course_category
    {
        $category = \core_course_category::get($id, IGNORE_MISSING);
        
        if (empty($category)){
            echo "Category ".$id." not found. Exiting.".PHP_EOL;
            if ($exit){
                exit -1;
            }
            return null;
        }

        return $category;
    }


    /**
     * Display message if verbose is set.
     * @param string $message message to display
     */
    private function verbose(string $message)
    {
        if ($this->verbose){
            echo $message . PHP_EOL;
        }
    }

    private function debug(string $message)
    {
        if ($this->debug){
            echo 'D: '. $message . PHP_EOL;
        }
    }

}
