<?php
/**
 * Mapeo de claves exactas utilizadas por Exertio Framework
 * Basado en el análisis del archivo /inc/emails.php
 */

class QvaClick_Exertio_Key_Mapping {
    
    /**
     * Mapeo exacto de las claves que usa Exertio Framework
     * Formato: 'base_key' => ['subject_key' => 'key', 'body_key' => 'key']
     */
    public static function get_exact_key_mapping() {
        return array(
            // New User Emails
            'fl_new_user_admin_email' => array(
                'subject_key' => 'fl_new_user_admin_sub',
                'body_key' => 'fl_new_user_admin_email_body'
            ),
            'fl_new_user_welcome_message' => array(
                'subject_key' => 'fl_new_user_welcome_sub', 
                'body_key' => 'fl_new_user_welcome_message_body'
            ),
            
            // Account Management
            'fl_user_email_verification' => array(
                'subject_key' => 'fl_user_email_verification_sub',
                'body_key' => 'fl_user_email_verification_message'
            ),
            'fl_user_email_account_activate' => array(
                'subject_key' => 'fl_user_email_account_activate_sub',
                'body_key' => 'fl_user_email_account_activate_message'
            ),
            'fl_user_email_account_deactivate' => array(
                'subject_key' => 'fl_user_email_account_deactivate_sub',
                'body_key' => 'fl_user_email_account_deactivate_message'
            ),
            'fl_email_sendto_Admin_account_activation' => array(
                'subject_key' => 'fl_email_sendto_Admin_account_activation_subj',
                'body_key' => 'fl_email_sendto_Admin_account_activation_body'
            ),
            
            // Password Reset
            'fl_user_reset' => array(
                'subject_key' => 'fl_user_reset_pwd_sub',
                'body_key' => 'fl_user_reset_message'
            ),
            
            // Project Emails
            'fl_email_onproject_created' => array(
                'subject_key' => 'fl_email_onproject_created_sub',
                'body_key' => 'fl_email_onproject_created_email_body'
            ),
            'fl_email_onproject_update' => array(
                'subject_key' => 'fl_email_onproject_update_sub',
                'body_key' => 'fl_email_onproject_update_email_body'
            ),
            
            // Service Emails
            'fl_onservice_created' => array(
                'subject_key' => 'fl_onservice_created_sub',
                'body_key' => 'fl_onservice_created_body'
            ),
            'fl_onservice_updated' => array(
                'subject_key' => 'fl_onservice_updated_sub',
                'body_key' => 'fl_onservice_updated_body'
            ),
            
            // Project Management
            'fl_freelancer_assign_project_message' => array(
                'subject_key' => 'fl_freelancer_assign_project_sub',
                'body_key' => 'fl_freelancer_assign_project_message_body'
            ),
            'fl_emp_assign_project_message' => array(
                'subject_key' => 'fl_emp_assign_project_sub', 
                'body_key' => 'fl_emp_assign_project_message_body'
            ),
            'fl_freelancer_complete_project_message' => array(
                'subject_key' => 'fl_freelancer_complete_project_sub',
                'body_key' => 'fl_freelancer_complete_project_message_body'
            ),
            'fl_emp_complete_project_message' => array(
                'subject_key' => 'fl_emp_complete_project_sub',
                'body_key' => 'fl_emp_complete_project_message_body'  
            ),
            'fl_freelancer_cancel_project_message' => array(
                'subject_key' => 'fl_freelancer_cancel_project_sub',
                'body_key' => 'fl_freelancer_cancel_project_message_body'
            ),
            'fl_emp_cancel_project_message' => array(
                'subject_key' => 'fl_emp_cancel_project_sub',
                'body_key' => 'fl_emp_cancel_project_message_body'
            ),
            
            // Project Invitations
            'fl_project_invitation_message' => array(
                'subject_key' => 'fl_project_invitation_sub',
                'body_key' => 'fl_project_invitation_message_body'
            ),
            'fl_project_invitation_accepted_message' => array(
                'subject_key' => 'fl_project_invitation_accepted_sub',
                'body_key' => 'fl_project_invitation_accepted_message_body'
            ),
            'fl_project_invitation_cancel_message' => array(
                'subject_key' => 'fl_project_invitation_cancel_sub',
                'body_key' => 'fl_project_invitation_cancel_message_body'
            ),
            
            // Other
            'fl_emp_addon_created_message' => array(
                'subject_key' => 'fl_emp_addon_created_sub',
                'body_key' => 'fl_emp_addon_created_message_body'
            ),
            'fl_email_onproject_pending_toadmin_email' => array(
                'subject_key' => 'fl_email_onproject_pending_toadmin_sub', 
                'body_key' => 'fl_email_onproject_pending_toadmin_email_body'
            ),
            'fl_project_proposal_message' => array(
                'subject_key' => 'fl_project_proposal_sub',
                'body_key' => 'fl_project_proposal_message_body'
            ),
            'fl_on_project_rejected' => array(
                'subject_key' => 'fl_on_project_rejected_sub',
                'body_key' => 'fl_on_project_rejected_body'
            ),
            'fl_allow_user_email_verification' => array(
                'subject_key' => 'fl_allow_user_email_verification_sub',
                'body_key' => 'fl_allow_user_email_verification_message'
            ),
            'fl_admin_email_account_activate' => array(
                'subject_key' => 'fl_admin_email_account_activate_sub',
                'body_key' => 'fl_admin_email_account_activate_message'
            )
        );
    }
    
    /**
     * Obtener la clave de subject correcta para un base_key
     */
    public static function get_subject_key($base_key) {
        $mapping = self::get_exact_key_mapping();
        return isset($mapping[$base_key]['subject_key']) ? $mapping[$base_key]['subject_key'] : $base_key . '_sub';
    }
    
    /**
     * Obtener la clave de body correcta para un base_key
     */
    public static function get_body_key($base_key) {
        $mapping = self::get_exact_key_mapping();
        return isset($mapping[$base_key]['body_key']) ? $mapping[$base_key]['body_key'] : $base_key . '_body';
    }
    
    /**
     * Verificar si un base_key está mapeado
     */
    public static function is_mapped($base_key) {
        $mapping = self::get_exact_key_mapping();
        return isset($mapping[$base_key]);
    }
    
    /**
     * Obtener todos los base_keys conocidos
     */
    public static function get_all_base_keys() {
        return array_keys(self::get_exact_key_mapping());
    }
}
?>
