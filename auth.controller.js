var path = require('path');
var loc = __dirname + '/../view';
const sendEmail = require('../email/email.send')
const msgs = require('../email/email.msgs')
const templates = require('../email/email.templates')
const User = require('../models/User')
const express = require('express');
const router = express.Router();
const bcrypt = require('bcryptjs');
const jwt = require('jsonwebtoken');
const config = require('config');
const { check, validationResult } = require('express-validator');
const Token = require('../models/Token');
var path = require('path');
const crypto = require('crypto');


exports.index = (req, res) => {
    res.sendFile(path.resolve('view/auth/login.html'));
}
exports.register = (req, res) => {
    res.sendFile(path.resolve('view/auth/reg.html'));

}
exports.login = async(req, res) => {
    const { email, password } = req.body;
    try {
      let user = await User.findOne({ email });
      if (!user) {
        let anonymousid = email;
        let user = await User.findOne({ anonymousid });
        if (!user){
          return res
          .status(400)
          .json({ errors: [{ msg: 'User does not exist!' }] });
        }else{ //TODO needs refactor
          //Check password validity
          const isMatch = await bcrypt.compare(password, user.password);
          let isConfirmed = user.confirmed

          if (!isMatch) {
            return res
              .status(400)
              .json({ errors: [{ msg: 'Wrong Password, try again!' }] });
          }

          if (isConfirmed) {
            console.error("Your account has not been verified.");
            return res
            .status(401)
            .send({errors: [{ msg: 'Your account has not been verified.' }]});
        }
          const payload = {
            user: {
              id: user.id
            }
          };

          jwt.sign(
            payload,
            config.get('jwtSecret'),
            { expiresIn: 360000 },
            (err, token) => {
              if (err) throw err;
              res.json({ token });
            }
          );
        }


      }else{
          //Check password validity
          const isMatch = await bcrypt.compare(password, user.password);

          if (!isMatch) {
            return res
              .status(400)
              .json({ errors: [{ msg: 'Wrong Password, try again!' }] });
          }

          const payload = {
            user: {
              id: user.id
            }
          };

          jwt.sign(
            payload,
            config.get('jwtSecret'),
            { expiresIn: 360000 },
            (err, token) => {
              if (err) throw err;
              res.json({ token });
            }
          );
      }


    } catch (err) {
      console.error(err.message);
      res.status(500).send('Server error');
    }
  }
  
exports.registerUser = async(req, res) => {
    const { anonymousid, email, password } = req.body;
	
    //var mypath = path.join(__dirname, 'config.json')
    //console.log('My Path:', mypath)

    //aws.config.loadFromPath(mypath);
   // var ses = new aws.SES({apiVersion: '2010-12-01'});
    try {

        let user = await User.findOne({ email });
        let userAnonymous = await User.findOne({ anonymousid });

         if (user) {
          return res
            .status(400)
            .json({ errors: [{ msg: 'User email already exists' }] });
        }

        if (userAnonymous) {
          return res
            .status(400)
            .json({ errors: [{ msg: 'Anonymous ID already taken' }] });
        }

        user = new User({
          anonymousid,
          email,
          password
        });
        const salt = await bcrypt.genSalt(10);
        user.password = await bcrypt.hash(password, salt);
        await user.save();

        var token = new Token({ _userId: user._id, token: crypto.randomBytes(16).toString('hex') });

        token.save(function (err) {
         if (err) { return res.status(500).send({ msg: err.message }); }

          var to = [email]
          var from = 'no-reply@theworklopedia.com';
          sendEmail(to, {
            subject: 'React Confirm Email',
            html: `
              <a href='http://localhost:5000/confirm/'>
                click to confirm email
              </a>
            `,
            text: `Copy and paste this link: http://localhost:5000/confirm/`
          },function(error, info){
            if(error){
              throw err
            }
            console.log('Message sent: ' + info.response);
        });
      });


        const payload = {
            user: {
              id: user.id
            }
          };

          jwt.sign(
            payload,
            config.get('jwtSecret'),
            { expiresIn: 360000 },
            (err, token) => {
              if (err) throw err;
              res.json({ token });
            }
          );


      } catch (err) {
        console.error(err.message);
        res.status(500).send('Server error');
      }

}

exports.confirm = async (req, res) => {
      var token = req.params.token;
    try {
		Token.findOne({ token: token }, function (err, token) {
        if (!token) return res.status(200).send({ type: 'not-verified', msg: 'We were unable to find a valid token. Your token may have expired.' });

        User.findOne({ _id: token._userId}, function (err, user) {
            if (!user) return res.status(200).send({ msg: 'We were unable to find a user for this token.' });
            if (user.confirmed) return res.status(200).send({ type: 'already-verified', msg: 'This user has already been verified.' });

            // Verify and save the user
            user.confirmed = true;
            user.save(function (err) {
                if (err) { return res.status(200).send({ msg: err.message }); }
                res.status(200).send("Account verified. Please log in.");
            });
        });
		});
    } catch (err) {
      console.error(err.message);
      res.status(500).send('Server Error');
    }
  }


