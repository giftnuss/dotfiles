;;;
;;; Copyright (c) 1997-2002 SuSE Gmbh Nuernberg, Germany.
;;;
;;; Author: Werner Fink, <feedback@suse.de> 1997,98,99,2002
;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;
(if (string-match "XEmacs\\|Lucid" emacs-version)
  (progn
     (if (file-readable-p "~/.xemacs/init.el")
        (load "~/.xemacs/init.el" nil t))
  )
  (if (file-readable-p "~/.gnu-emacs")
      (load "~/.gnu-emacs" nil t))

  (setq custom-file "~/.gnu-emacs-custom")
  (load "~/.gnu-emacs-custom" t t)
)
;;;
