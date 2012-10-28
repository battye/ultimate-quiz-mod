<?php
/**
*
* @package phpBB3
* @version $Id$
* @copyright (c) 2005 phpBB Group
* @license http://opensource.org/licenses/gpl-license.php GNU Public License
*
*/

/**
* @ignore
*/
define('IN_PHPBB', true);
$phpbb_root_path = (defined('PHPBB_ROOT_PATH')) ? PHPBB_ROOT_PATH : './';
$phpEx = substr(strrchr(__FILE__, '.'), 1);
include($phpbb_root_path . 'common.' . $phpEx);

// Start session management
$user->session_begin();
$auth->acl($user->data);
$user->setup('mods/quiz');

// Only allow registered users to see quizzes
if( !$user->data['is_registered'] )
{
	trigger_error('UQM_QUIZ_FOR_REGISTERED_USERS');
}

include($phpbb_root_path . 'includes/quiz/quiz.' . $phpEx);

$mode 		= request_var('mode', '');
$quiz_id 	= request_var('q', 0);

$quiz_configuration = new quiz_configuration;
$quiz_configuration->load();

// Put in "quiz" breadcrumb
$quiz_configuration->breadcrumbs( array($user->lang['UQM_QUIZ'] => append_sid('quiz.'. $phpEx)) );

switch($mode)
{
	case 'submit':
		$quiz_configuration->breadcrumbs( array($user->lang['UQM_SUBMIT_QUIZ'] => append_sid('quiz.'. $phpEx, 'mode=submit')) );
		page_header($user->lang['UQM_SUBMIT_QUIZ']);

		$auth_params = array(
			'administrator'		=> $auth->acl_get('a_'),
			'submit_setting'	=> $quiz_configuration->value('qc_admin_submit_only'),
			'return_value'		=> false,
		);

		$quiz_configuration->auth('submit', $auth_params);

		$enter_answers  = (!empty($_POST['submit'])) ? true : false;
		$alter_question = (!empty($_POST['alter_question'])) ? true : false;
		$submit_db	= (!empty($_POST['submit_db'])) ? true : false;

		$quiz_message	   = ''; // a message in case something unexpected happens and goes wrong
		$current_questions = request_var('question_number', $quiz_configuration->value('qc_minimum_questions'));

		// Enter the questions and answers into the database
		if( $submit_db )
		{
			if( !check_form_key('uqm_submit') )
			{
				trigger_error('UQM_QUIZ_FORM_INVALID');
			}

			$check_correct	= $quiz_configuration->check_correct_checked($current_questions); // see if the user has left any empty
			$quiz_name	= request_var('quiz_name', '');		
			$quiz_category	= request_var('category', 1); // default to the first category

			if( $check_correct && $quiz_name )
			{
				$quiz_question = new quiz_question;
				$quiz_question->insert( $quiz_question->refresh_obtain(), $quiz_name, $quiz_category );

				trigger_error( sprintf($user->lang['UQM_QUIZ_SUBMITTED'], '<a href="' . append_sid("{$phpbb_root_path}quiz.$phpEx") . '">', '</a>') );
			}
	
			else
			{
				// If the user has missed an answer, bring the page back up with a message
				$alter_question	= true;
				$enter_answers	= true;
				$quiz_message	= $user->lang['UQM_ENTER_ALL_CORRECT'];
			}
		}

		$empty_twist	   = false;

		// We want to populate the fields if the page has been submitted, otherwise do not worry
		$populate_fields = null;
		$populate_size	 = 0;

		// If the user wants to add or remove a question from the quiz (and not trying, for some reason, to submit
		// the quiz at the same time or submit to the database!)
		if( $enter_answers && !$submit_db )
		{
			// And here we begin the populating, but with a twist
			// if $empty_twist is TRUE after being passed by reference then there are some empty fields still
			$empty_twist = false;

			$quiz_question = new quiz_question;
			$populate_fields = $quiz_question->refresh_obtain($empty_twist);
			$populate_size = sizeof($populate_fields);

			if( $empty_twist )
			{
				$quiz_message = $user->lang['UQM_ENSURE_FIELDS_ARE_FILLED'];
				$enter_answers = false;
			}
		}

		else if( $alter_question ) // if the user is trying to add or remove a question
		{
			// And here we begin the populating
			$quiz_question = new quiz_question;
			$populate_fields = $quiz_question->refresh_obtain();
			$populate_size = sizeof($populate_fields);

			// Now we want a mechanism so users don't somehow add outside the allowed number of questions
			switch( request_var('alter_question', '') )
			{
				case $user->lang['UQM_PLUS_QUESTION']:
					$alter_question_value = 1;
					break;
				case $user->lang['UQM_MINUS_QUESTION']:
					$alter_question_value = -1;
					break;
				default:
					$alter_question_value = 0;
			}

			if( $quiz_configuration->check_question_boundaries($current_questions, $alter_question_value) )
			{
				$current_questions = $current_questions + $alter_question_value;
			}

			else // show a message to the user
			{
				$quiz_message = sprintf($user->lang['UQM_QUESTION_BOUNDARY_VIOLATE'], $quiz_configuration->value('qc_minimum_questions'), $quiz_configuration->value('qc_maximum_questions'));	
			}
		}

		// Show the questions, and if the add or remove button has been clicked act accordingly
		for($i = 0; $i < $current_questions; $i++)
		{
			// If confirming the answers, get the array. Otherwise condense the answer, or show nothing.
			$existing_answers  = ($i < $populate_size) ? $populate_fields[$i]->show_answers(true) : '';
			$existing_question = ($i < $populate_size) ? $populate_fields[$i]->show_question() : '';

			$template->assign_block_vars('question_row', array(
				'U_QUESTION'			=> $existing_question,
				'U_ANSWERS'			=> $existing_answers,

				'U_QUESTION_ID'			=> $i,
				'U_MAX_MULTIPLE_CHOICE' 	=> $quiz_configuration->value('qc_maximum_choices'),
			));

			// Have the user select the correct answer
			if( $enter_answers )
			{
				// Deal with the form key
				add_form_key('uqm_submit');

				$temp_answer = $populate_fields[$i]->show_answers();
				$answer_id = 0;

				foreach( $temp_answer as $answer )
				{
					$template->assign_block_vars('question_row.answer_row', array(
					'U_ANSWER_ID'			=> $answer_id++,
					'U_ANSWER_NAME'			=> $answer,	
					));
				}
			}

		}

		$s_hidden_fields = build_hidden_fields(array(
			'question_number'	=> $current_questions,
			'submit_db'		=> ($enter_answers) ? true : false,
		));

		// Only show the add and remove buttons if within the boundaries
		$allow_adding 	= ($current_questions < $quiz_configuration->value('qc_maximum_questions')) ? true : false;
		$allow_removing	= ($current_questions > $quiz_configuration->value('qc_minimum_questions')) ? true : false;

		$template->assign_vars( array(
			'S_HIDDEN_FIELDS'	=> $s_hidden_fields,
			'S_SUBMIT_QUIZ_ACTION'	=> append_sid("{$phpbb_root_path}quiz.$phpEx", 'mode=submit'),

			'U_UQM_CONFIRM'		=> $enter_answers,
			'U_UQM_DISPLAY_ADD'	=> ($enter_answers) ? false : $allow_adding,
			'U_UQM_DISPLAY_REMOVE'	=> ($enter_answers) ? false : $allow_removing,
			'U_UQM_DISPLAY_MESSAGE'	=> $quiz_message,
			'U_QUIZ_CATEGORY_SELECT'=> $quiz_configuration->categories(),
		));

		$template->set_filenames(array(
			'body' => 'quiz_submit_body.html')
		);

		page_footer();

		break;
	
	case 'play':
		$quiz_id = request_var('q', 0);
		$quiz_configuration->breadcrumbs( array($user->lang['UQM_QUIZ_PLAY'] => append_sid('quiz.'. $phpEx, 'mode=play&amp;q=' . $quiz_id)) );	
		page_header($user->lang['UQM_QUIZ_PLAY']);

		$quiz_information = $quiz_configuration->determine_quiz_core($quiz_id);

		if( !$quiz_information['quiz_id'] || !$quiz_id )
		{
			trigger_error('UQM_EDIT_NO_QUIZ');
		}

		$play = new quiz_question;
		$play_quiz = $play->play($quiz_id); // Get the array of quiz question objects for this quiz
		$count = 0;

		// Check results, as the user has submitted their answers
		if( !empty($_POST['submit']) )
		{
			if( !check_form_key('uqm_play') )
			{
				trigger_error('UQM_QUIZ_FORM_INVALID');
			}

			// Keep track of the users' progress
			$user_correct_answers	= 0;
			$user_incorrect_answers	= 0;

			foreach( $play_quiz as $question )
			{
				// Get the actual information
				$actual_answer 		= $question->show_correct();
				$question_answers 	= $question->show_answers();

				// Get the user submitted information, starting with the id of the user selected answer
				
				$user_submitted_id = request_var('answer_' . $count, -1); 

				// ensure the user has selected an answer by ensuring it is in the question boundary,
				// $db_answer is the corresponding data entry for whatever option the user
				// selected - not necessarily the correct answer
				$db_answer = ($user_submitted_id >= 0 && $user_submitted_id < sizeof($question_answers)) ? $question_answers[$user_submitted_id] : null;

				// Is the users' answer correct or not?
				$is_correct = ($db_answer == $actual_answer) ? true : false;

				// Update progress count
				($is_correct) ? $user_correct_answers++ : $user_incorrect_answers++;

				if( $quiz_configuration->value('qc_show_answers') )
				{
					$template->assign_block_vars('result_row', array(
						'U_QUESTION_NAME'	=> $question->show_question(),
						'U_STATUS'		=> $is_correct,
						'U_MESSAGE'		=> $question->obtain_result_data($actual_answer, $db_answer, $question->show_question_id()),
					));

				}

				$count++;
			}

			$result_percentage = $quiz_configuration->determine_percentage($user_correct_answers, $user_incorrect_answers);

			$template->assign_vars( array(
				'U_QUIZ_RESULTS'	=> sprintf($user->lang['UQM_RESULTS_FOR_QUIZ'], $quiz_information['quiz_name']),
				'U_QUIZ_SUMMARY'	=> sprintf($user->lang['UQM_RESULTS_SUMMARY'], $user_correct_answers, $user_incorrect_answers, $result_percentage),
				'U_SHOW_ANSWERS'	=> $quiz_configuration->value('qc_show_answers'),
				'U_RETURN_TO_INDEX'	=> sprintf($user->lang['UQM_RESULTS_RETURN_TO_INDEX'], '<a href="' . append_sid('quiz.'.$phpEx) . '">', '</a>'),
			));

			$template->set_filenames(array(
				'body' => 'quiz_results_body.html')
			);

			// Update the statistics, as the SQL array's are still stored in the static variable
			$question->obtain_result_data();

			// Finish the results by checking if cash compatibility is enabled
			$quiz_configuration->cash($user_correct_answers, $user_incorrect_answers);

			page_footer();
		}

		foreach( $play_quiz as $question )
		{
			$template->assign_block_vars('question_row', array(
				'U_QUESTION_ID'		=> $count,
				'U_QUESTION_NAME'	=> $question->show_question(),
			));

			$question_answers = $question->show_answers();
			$answer_size = sizeof($question_answers);

			for( $i = 0; $i < $answer_size; $i++ )
			{
				$template->assign_block_vars('question_row.answer_row', array(
					'U_ANSWER_ID'		=> $i,
					'U_ANSWER_NAME'		=> $question_answers[$i],
				));
			}

			$count++;	
		}

		// Add the form key
		add_form_key('uqm_play');

		$template->assign_vars( array(
			'S_SUBMIT_QUIZ_ACTION'	=> append_sid("{$phpbb_root_path}quiz.$phpEx", 'mode=play&amp;q=' . $quiz_id),
			'U_QUIZ_NAME'		=> $quiz_information['quiz_name'],
			'U_POSTED_INFORMATION'	=> sprintf($user->lang['UQM_QUIZ_AUTHOR_DETAILS'], get_username_string('full', $quiz_information['user_id'], $quiz_information['username'], $quiz_information['user_colour']), $user->format_date($quiz_information['quiz_time'])),
		));
		
		$template->set_filenames(array(
			'body' => 'quiz_play_body.html')
		);

		page_footer();

		break;

	case 'statistics':
		$quiz_id = request_var('q', 0);
		$quiz_configuration->breadcrumbs( array($user->lang['UQM_QUIZ_STATISTICS'] => append_sid('quiz.'. $phpEx, 'mode=statistics&amp;q=' . $quiz_id)) );	
		page_header($user->lang['UQM_QUIZ_STATISTICS']);

		$quiz_information = $quiz_configuration->determine_quiz_core($quiz_id);

		if( !$quiz_information['quiz_id'] )
		{
			trigger_error('UQM_EDIT_NO_QUIZ');
		}

		if( $quiz_id )
		{
			$quiz_statistics = new quiz_statistics;
			$quiz_statistics->initialise($quiz_id);

			// Determine if the user viewing this page is allowed to
			$auth_params = array(
				'quiz_information'	=> $quiz_information,
				'user_id'		=> (int) $user->data['user_id'],
				'played_quiz'		=> $quiz_statistics->has_user_played_quiz($quiz_id, $user->data['user_id']),
				'administrator'		=> $auth->acl_get('a_'),
				'return_value'		=> false,
			);

			$quiz_configuration->auth('statistics', $auth_params);

			$quiz_statistics->average_scores();
			$quiz_statistics->question_summary();
			$quiz_statistics->survey();
		}

		$template->set_filenames(array(
			'body' => 'quiz_statistics_body.html')
		);

		page_footer();

		break;

	case 'edit':
		$quiz_id = request_var('q', 0);
		$quiz_configuration->breadcrumbs( array($user->lang['UQM_EDIT_QUIZ'] => append_sid('quiz.'. $phpEx, 'mode=edit&amp;q=' . $quiz_id)) );	
		page_header($user->lang['UQM_EDIT_QUIZ']);

		$quiz_information = $quiz_configuration->determine_quiz_core($quiz_id);

		if( !$quiz_information['quiz_id'] )
		{
			trigger_error('UQM_EDIT_NO_QUIZ');
		}

		// Determine if the user viewing the edit page is allowed to
		$auth_params = array(
			'quiz_information'	=> $quiz_information,
			'user_id'		=> (int) $user->data['user_id'],
			'administrator'		=> $auth->acl_get('a_'),
			'return_value'		=> false,
		);

		$quiz_configuration->auth('edit', $auth_params);
		$display_message = '';

		// Try submitting, but only if everything is in order
		if( !empty($_POST['submit']) )
		{
			if( !check_form_key('uqm_edit') )
			{
				trigger_error('UQM_QUIZ_FORM_INVALID');
			}

			$id_array = $quiz_configuration->determine_quiz_questions($quiz_id);

			// Does the user want to delete the quiz? If so, delete all of its contents
			if( !empty($_POST['delete_quiz']) )
			{
				$quiz_question = new quiz_question;
				$quiz_question->delete($quiz_id, $id_array);

				trigger_error( sprintf($user->lang['UQM_DELETE_QUIZ_SUBMITTED'], '<a href="' . append_sid("{$phpbb_root_path}quiz.$phpEx") . '">', '</a>') );
			}

			// On with simply editing the question then!
			$question_number = request_var('question_number', $quiz_configuration->value('qc_minimum_questions'));
			$quiz_name = request_var('quiz_name', $quiz_information['quiz_name']);
			$new_category = request_var('category', 1);
			$question_array = array();

			// Iterate through the question ids
			foreach($id_array as $i)
			{
				$quiz_question	= new quiz_question;
				$answer_array	= array();
				$answer_count	= 0;

				$question_name	= request_var('question_name_' . $i, '');
				$answer		= request_var('user_answer_' . $i . '_' . $answer_count, '');
				$correct_answer	= request_var('user_answer_' . $i . '_' . request_var('answer_' . $i, -1), '');

				// Loop through the multiple answers until there are no more
				while( !empty($answer) )
				{
					$answer_array[] = $answer;
					$answer_count++;

					// Update the answer value with the next...
					$answer = request_var('user_answer_' . $i . '_' . $answer_count, '');
				}

				// No answer was given, or no CORRECT answer was given so break from the loop and notify
				// the user of the problem
				if( $answer_count < 1 || !$correct_answer || !$question_name )
				{
					$display_message = $user->lang['UQM_EDIT_VERIFY_ANSWERS'];
					break;
				}

				$quiz_question->initialise($question_name, $answer_array, $correct_answer, $i);
				$question_array[] = $quiz_question;

				unset($quiz_question);
			}

		// Prepare the quiz for updating in the database by calling the update function in quiz_question
		$new_quiz_name 	= ($quiz_name != $quiz_information['quiz_name']) ? $quiz_name : null;
		$update_quiz = new quiz_question;
		$update_quiz->update($question_array, $new_quiz_name, $quiz_id, $new_category);
		
		trigger_error( sprintf($user->lang['UQM_EDIT_QUIZ_SUBMITTED'], '<a href="' . append_sid("{$phpbb_root_path}quiz.$phpEx") . '">', '</a>') );
		}
		
		$quiz_question = new quiz_question;
		$questions_list = $quiz_question->edit($quiz_id);

		foreach($questions_list as $question)
		{
			$answers_list = $question->show_answers();
			$correct_answer = $question->show_correct();

			$template->assign_block_vars('question_row', array(
				'U_QUESTION_ID'		=> $question->show_question_id(),
				'U_QUESTION_NAME'	=> $question->show_question(),
			));

			$i = 0;
			foreach($answers_list as $answer)
			{
				$template->assign_block_vars('question_row.answer_row', array(
					'U_ANSWER_ID'	=> $i,
					'U_ANSWER_NAME'	=> $answer,
					'U_CORRECT'	=> ($answer == $correct_answer) ? true : false,
				));
				
				$i++;			
			}
		}

		// Handle the form key
		add_form_key('uqm_edit');

		$s_hidden_fields = build_hidden_fields(array(
			'question_number'	=> sizeof($questions_list),
		));

		$template->assign_vars( array(
			'S_HIDDEN_FIELDS'	=> $s_hidden_fields,
			'U_QUIZ_NAME' 		=> $quiz_information['quiz_name'],
			'U_UQM_DISPLAY_MESSAGE'	=> $display_message,
			'U_QUIZ_CATEGORY_SELECT'=> $quiz_configuration->categories($quiz_information['quiz_category']),
		));

		$template->set_filenames(array(
			'body' => 'quiz_edit_body.html')
		);

		page_footer();		

		break;

	default:
		page_header($user->lang['UQM_QUIZ']);
		
		$category_id 	= request_var('c', 0);
		$category_and	= '';

		if( $category_id )
		{
			$category_and = 'AND c.quiz_category_id = ' . (int) $category_id;
		}

		$sql = 'SELECT q.*, c.* FROM ' . QUIZ_TABLE . ' q, ' . QUIZ_CATEGORIES_TABLE . ' c
			WHERE q.quiz_category = c.quiz_category_id ' . $category_and . '
			ORDER BY c.quiz_category_name ASC, q.quiz_id DESC';
		$result = $db->sql_query($sql);

		$category_id_list	= array();
		$category_name_list	= array();
		$quiz_list		= array();

		while( $row = $db->sql_fetchrow($result) )
		{
			$category_id_list[] 				= $row['quiz_category_id'];
			$category_name_list[$row['quiz_category_id']]	= $row['quiz_category_name'];
			$quiz_list[$row['quiz_category_id']][]		= $row; 
		}

		foreach( array_unique($category_id_list) as $cat_id )
		{
			$template->assign_block_vars('category_row', array(
				'U_CATEGORY_NAME'		=> $category_name_list[$cat_id],
				'U_CATEGORY_LINK'		=> append_sid('quiz.'.$phpEx, 'c='.$cat_id),
			));

			// iterate through each category
			foreach( $quiz_list[$cat_id] as $quiz_row )
			{
				$quiz_statistics = new quiz_statistics;
				$auth_params = array(
					'quiz_information'	=> $quiz_row,
					'user_id'		=> (int) $user->data['user_id'],
					'played_quiz'		=> $quiz_statistics->has_user_played_quiz($quiz_row['quiz_id'], $user->data['user_id']),
					'administrator'		=> $auth->acl_get('a_'),
					'submit_setting'	=> $quiz_configuration->value('qc_admin_submit_only'),
					'return_value'		=> true,
				);

				$template->assign_block_vars('category_row.quiz_row', array(
					'U_QUIZ_NAME'			=> $quiz_row['quiz_name'],
					'U_QUIZ_LINK'			=> append_sid("{$phpbb_root_path}quiz.$phpEx", 'mode=play&amp;q=' . $quiz_row['quiz_id']),
					'U_QUIZ_AUTHOR'			=> sprintf($user->lang['UQM_QUIZ_SUBMITTED_BY'], get_username_string('full', $quiz_row['user_id'], $quiz_row['username'], $quiz_row['user_colour'])),	
					'U_QUIZ_DATE'			=> $user->format_date($quiz_row['quiz_time']),
					'U_QUIZ_INFO'			=> $quiz_configuration->determine_quiz_information($auth_params),
				));				
			}
		}

		$db->sql_freeresult($result);

		$submit_auth_params = array(
			'administrator' => $auth->acl_get('a_'), 
			'submit_setting' => $quiz_configuration->value('qc_admin_submit_only'), 
			'return_value' => true,
		);

		$template->assign_vars( array(
			'L_SUBMIT_UPPER'		=> strtoupper($user->lang['UQM_SUBMIT_QUIZ']),

			'U_UQM_SUBMIT'			=> ($quiz_configuration->auth('submit', $submit_auth_params)) ? append_sid("{$phpbb_root_path}quiz.$phpEx", 'mode=submit') : '',
			'U_UQM_STATS'			=> append_sid("{$phpbb_root_path}quiz.$phpEx", 'mode=statistics'),
		));

		$template->set_filenames(array(
			'body' => 'quiz_body.html')
		);

		page_footer();
}
?>