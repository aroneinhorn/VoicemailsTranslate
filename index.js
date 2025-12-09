const express = require('express');
const nodemailer = require('nodemailer');

const app = express();
app.use(express.json());

app.get('/health', (req, res) => {
  res.status(200).send('Alive');
});

app.post('/', async (req, res) => {
  const data = req.body;

  // Only respond to completed transcription
  if (
    data.event === 'transcription.completed' &&
    data.data &&
    data.data.text
  ) {
    const transcript = data.data.text;
    const recordingName = data.data.name || 'Voicemail';

    // Set static recipient
    const to = 'user@example.com';
    const subject = `Voicemail Transcription: ${recordingName}`;
    const message = `Here is your voicemail transcription:\n\n${transcript}`;

    // Setup nodemailer (for example, using Gmail - recommend using environment vars for real deployment)
    let transporter = nodemailer.createTransport({
      service: 'gmail',
      auth: {
        user: process.env.EMAIL_USER,      // replace with your email
        pass: process.env.EMAIL_PASS          // replace with your app password
      }
    });

    try {
      await transporter.sendMail({
        from: '"Webhook" <aroneinhornofficescan@gmail.com>',
        to,
        subject,
        text: message
      });
      console.log('Mail sent!');
    } catch (err) {
      console.error('Mailer error:', err);
    }
  }
  res.status(200).send('OK');
});

const PORT = process.env.PORT || 3000;
app.listen(PORT, () =>
  console.log(`Server running on port ${PORT}`)

);

